<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

class SiteGitDeployer
{
    public function run(Site $site): array
    {
        if (($site->deploy_strategy ?? 'simple') === 'atomic') {
            return app(AtomicSiteDeployer::class)->deploy($site);
        }

        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $repo = trim((string) $site->git_repository_url);
        if ($repo === '') {
            throw new \InvalidArgumentException('Set a Git repository URL first.');
        }

        $path = $site->effectiveRepositoryPath();
        $branch = $site->git_branch ?: 'main';
        $ssh = new SshConnection($server);

        $keyPath = '/root/.ssh/dply_site_'.$site->id.'_deploy';
        $privateKey = $site->git_deploy_key_private;
        if ($privateKey) {
            $ssh->putFile($keyPath, $privateKey);
            $ssh->exec('chmod 600 '.escapeshellarg($keyPath));
        }

        $gitSsh = $privateKey
            ? 'export GIT_SSH_COMMAND='.escapeshellarg('ssh -i '.$keyPath.' -o StrictHostKeyChecking=accept-new').' && '
            : '';

        $log = '';
        $repoEsc = escapeshellarg($repo);
        $pathEsc = escapeshellarg($path);
        $branchEsc = escapeshellarg($branch);

        $log .= $ssh->exec(
            sprintf('mkdir -p %s', $pathEsc),
            60
        );

        $checkGit = trim($ssh->exec(sprintf('if [ -d %1$s/.git ]; then echo yes; else echo no; fi', $pathEsc), 30));

        if ($checkGit !== 'yes') {
            $log .= "\n--- git clone ---\n";
            $log .= $ssh->exec(
                $gitSsh.sprintf('git clone --branch %s %s %s 2>&1', $branchEsc, $repoEsc, $pathEsc),
                600
            );
        } else {
            $log .= "\n--- git fetch ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git fetch origin 2>&1', $pathEsc, $gitSsh),
                300
            );
            $log .= "\n--- git checkout ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git checkout %s 2>&1', $pathEsc, $gitSsh, $branchEsc),
                120
            );
            $log .= "\n--- git pull ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git pull origin %s 2>&1', $pathEsc, $gitSsh, $branchEsc),
                300
            );
        }

        $sha = trim($ssh->exec(sprintf('cd %s && git rev-parse HEAD 2>/dev/null', $pathEsc), 30));

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $log .= "\n--- post deploy ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s', $pathEsc, $post),
                900
            );
        }

        return ['output' => $log, 'sha' => $sha !== '' ? $sha : null];
    }
}
