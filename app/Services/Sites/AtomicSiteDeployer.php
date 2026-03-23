<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteRelease;
use App\Services\SshConnectionFactory;

class AtomicSiteDeployer
{
    public function __construct(
        protected DeployHookRunner $hookRunner,
        protected SiteDeployPipelineRunner $pipelineRunner,
        protected SshConnectionFactory $sshFactory
    ) {}

    public function deploy(Site $site): array
    {
        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $repo = trim((string) $site->git_repository_url);
        if ($repo === '') {
            throw new \InvalidArgumentException('Set a Git repository URL first.');
        }

        $base = rtrim($site->effectiveRepositoryPath(), '/');
        $branch = $site->git_branch ?: 'main';
        $ssh = $this->sshFactory->forServer($server);
        $log = '';

        $keyPath = '/root/.ssh/dply_site_'.$site->id.'_deploy';
        $privateKey = $site->git_deploy_key_private;
        if ($privateKey) {
            $ssh->putFile($keyPath, $privateKey);
            $ssh->exec('chmod 600 '.escapeshellarg($keyPath));
        }

        $gitSsh = $privateKey
            ? 'export GIT_SSH_COMMAND='.escapeshellarg('ssh -i '.$keyPath.' -o StrictHostKeyChecking=accept-new').' && '
            : '';

        $folder = gmdate('YmdHis');
        $releasesDir = $base.'/releases';
        $newRelease = $releasesDir.'/'.$folder;

        $baseEsc = escapeshellarg($base);
        $newEsc = escapeshellarg($newRelease);
        $repoEsc = escapeshellarg($repo);
        $branchEsc = escapeshellarg($branch);

        $log .= $ssh->exec("mkdir -p {$baseEsc}/releases", 60);

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $base);
        $this->hookRunner->assertHooksSucceeded($log, 'before_clone');

        $log .= "\n--- git clone (atomic) ---\n";
        $log .= $ssh->exec(
            $gitSsh.sprintf('git clone --depth 1 --branch %s %s %s 2>&1', $branchEsc, $repoEsc, $newEsc),
            600
        );

        $hasGit = trim($ssh->exec(sprintf('test -d %s/.git && echo ok', $newEsc), 30));
        if ($hasGit !== 'ok') {
            throw new \RuntimeException('Git clone failed. See deployment log.');
        }

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $newRelease);
        $this->hookRunner->assertHooksSucceeded($log, 'after_clone');

        $log .= $this->pipelineRunner->run($ssh, $site, $newRelease);

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $log .= "\n--- post deploy ---\n";
            $log .= $ssh->exec(sprintf('cd %s && %s', $newEsc, $post), 900);
        }

        $log .= "\n--- activate release ---\n";
        $log .= $ssh->exec(sprintf('ln -sfn %s %s/current', $newEsc, $baseEsc), 60);

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $base.'/current');
        $this->hookRunner->assertHooksSucceeded($log, 'after_activate');

        $sha = trim($ssh->exec(sprintf('cd %s && git rev-parse HEAD 2>/dev/null', $newEsc), 30));

        $keep = max(1, min(50, (int) ($site->releases_to_keep ?? 5)));
        $log .= "\n--- prune old releases ---\n";
        $log .= $ssh->exec(
            sprintf(
                'cd %s/releases 2>/dev/null && ls -1t 2>/dev/null | tail -n +%d | while read -r d; do rm -rf "$d"; done; echo done',
                $baseEsc,
                $keep + 1
            ),
            120
        );

        SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
        SiteRelease::query()->create([
            'site_id' => $site->id,
            'folder' => $folder,
            'git_sha' => $sha !== '' ? $sha : null,
            'is_active' => true,
        ]);

        return ['output' => $log, 'sha' => $sha !== '' ? $sha : null, 'release_folder' => $folder];
    }
}
