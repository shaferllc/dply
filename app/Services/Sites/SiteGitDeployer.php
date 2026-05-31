<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Servers\SupervisorDeployRestarter;
use App\Services\SshConnectionFactory;
use App\Support\Sites\DeployPipelineBranchResolver;

class SiteGitDeployer
{
    public function __construct(
        protected DeployHookRunner $hookRunner,
        protected SiteDeployPipelineRunner $pipelineRunner,
        protected PipelineAnchorScriptRunner $anchorRunner,
        protected SshConnectionFactory $sshFactory
    ) {}

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

        $path = rtrim($site->effectiveRepositoryPath(), '/');
        $branch = $site->git_branch ?: 'main';
        app(DeployPipelineBranchResolver::class)->applyForDeploy($site, $branch);
        $ssh = $this->sshFactory->forServer($server);

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
        $pathEsc = escapeshellarg($path);

        $log .= $ssh->exec(
            sprintf('mkdir -p %s', $pathEsc),
            60
        );

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'before_clone');

        $checkGit = trim($ssh->exec(sprintf('if [ -d %1$s/.git ]; then echo yes; else echo no; fi', $pathEsc), 30)) === 'yes';

        $log .= $this->anchorRunner->runClone($ssh, $site, $path, $gitSsh, $repo, $branch, false, $checkGit);
        $this->hookRunner->assertHooksSucceeded($log, 'clone');

        $sha = trim($ssh->exec(sprintf('cd %s && git rev-parse HEAD 2>/dev/null', $pathEsc), 30));

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'after_clone');

        $log .= $this->pipelineRunner->runBuild($ssh, $site, $path);

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'before_activate');

        $log .= $this->anchorRunner->runActivate($ssh, $site, $path, $gitSsh, $repo, $branch);
        $this->hookRunner->assertHooksSucceeded($log, 'activate');

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $log .= "\n--- post deploy ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s', $pathEsc, $post),
                900
            );
        }

        $log .= $this->pipelineRunner->runRelease($ssh, $site, $path);

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'after_activate');

        $log .= app(SupervisorDeployRestarter::class)->restartAfterDeployIfEnabled($site);

        $syncResult = app(ByoRepoConfigSync::class)->syncAfterDeploy($site, $ssh, $path);
        if ($syncResult['applied']) {
            $log .= "\n--- dply.yaml sync ---\n";
            $log .= sprintf(
                "Synced %s: %d redirects, %d site crons, %d server crons, %d deploy hooks.\n",
                (string) ($syncResult['source_path'] ?? 'dply.yaml'),
                $syncResult['redirects'],
                $syncResult['crons'],
                $syncResult['server_crons'],
                $syncResult['deploy_hooks'],
            );
            if ($syncResult['warnings'] !== []) {
                $log .= "Warnings:\n- ".implode("\n- ", $syncResult['warnings'])."\n";
            }
        }

        return ['output' => $log, 'sha' => $sha !== '' ? $sha : null];
    }
}
