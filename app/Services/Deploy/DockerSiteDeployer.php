<?php

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Sites\DeployHookRunner;
use App\Services\Sites\SiteDeployPipelineRunner;
use App\Services\SshConnectionFactory;

final class DockerSiteDeployer
{
    public function __construct(
        private readonly DeployHookRunner $hookRunner,
        private readonly SiteDeployPipelineRunner $pipelineRunner,
        private readonly SshConnectionFactory $sshFactory,
        private readonly DockerComposeArtifactBuilder $composeBuilder,
        private readonly DockerRuntimeDockerfileBuilder $dockerfileBuilder,
    ) {}

    /**
     * @return array{output: string, sha: ?string, compose_yaml: string, dockerfile: string}
     */
    public function deploy(Site $site): array
    {
        $server = $site->server;
        if (! $server || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Docker host must be reachable over SSH with a configured private key.');
        }

        $repo = trim((string) $site->git_repository_url);
        if ($repo === '') {
            throw new \InvalidArgumentException('Set a Git repository URL first.');
        }

        $path = rtrim($site->effectiveRepositoryPath(), '/');
        $branch = $site->git_branch ?: 'main';
        $compose = $this->composeBuilder->build($site);
        $dockerfile = $this->dockerfileBuilder->build($site);
        $ssh = $this->sshFactory->forServer($server);

        $log = '';
        $repoEsc = escapeshellarg($repo);
        $pathEsc = escapeshellarg($path);
        $branchEsc = escapeshellarg($branch);

        $keyPath = '/root/.ssh/dply_site_'.$site->id.'_deploy';
        $privateKey = $site->git_deploy_key_private;
        if ($privateKey) {
            $ssh->putFile($keyPath, $privateKey);
            $ssh->exec('chmod 600 '.escapeshellarg($keyPath));
        }

        $gitSsh = $privateKey
            ? 'export GIT_SSH_COMMAND='.escapeshellarg('ssh -i '.$keyPath.' -o StrictHostKeyChecking=accept-new').' && '
            : '';

        $log .= $ssh->exec(sprintf('mkdir -p %s', $pathEsc), 60);
        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'before_clone');

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
        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'after_clone');

        $ssh->putFile($path.'/docker-compose.dply.yml', $compose);
        $ssh->putFile($path.'/Dockerfile.dply', $dockerfile);

        $log .= "\n--- docker runtime files ---\n";
        $log .= "Wrote docker-compose.dply.yml and Dockerfile.dply\n";

        $log .= $this->pipelineRunner->run($ssh, $site, $path);

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $log .= "\n--- post deploy ---\n";
            $log .= $ssh->exec(sprintf('cd %s && %s', $pathEsc, $post), 900);
        }

        $log .= "\n--- docker compose up ---\n";
        $log .= $ssh->exec(
            sprintf('cd %s && docker compose -f docker-compose.dply.yml up -d --build 2>&1', $pathEsc),
            1800
        );

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'after_activate');

        return [
            'output' => $log,
            'sha' => $sha !== '' ? $sha : null,
            'compose_yaml' => $compose,
            'dockerfile' => $dockerfile,
        ];
    }
}
