<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteRelease;
use App\Services\Servers\SupervisorDeployRestarter;
use App\Services\SshConnectionFactory;
use App\Support\Sites\DeployPipelineBranchResolver;

class AtomicSiteDeployer
{
    public function __construct(
        protected DeployHookRunner $hookRunner,
        protected SiteDeployPipelineRunner $pipelineRunner,
        protected PipelineAnchorScriptRunner $anchorRunner,
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
        app(DeployPipelineBranchResolver::class)->applyForDeploy($site, $branch);
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

        $log .= $ssh->exec("mkdir -p {$baseEsc}/releases", 60);

        $previousActiveRelease = SiteRelease::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $base);
        $this->hookRunner->assertHooksSucceeded($log, 'before_clone');

        $log .= $this->anchorRunner->runClone($ssh, $site, $newRelease, $gitSsh, $repo, $branch, true, false);
        $this->hookRunner->assertHooksSucceeded($log, 'clone');
        $this->anchorRunner->assertReleaseHasGit($ssh, $newRelease);

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $newRelease);
        $this->hookRunner->assertHooksSucceeded($log, 'after_clone');

        app(VmSiteComposerDetectionPersister::class)->persistFromReleasePath($site, $ssh, $newRelease);

        $log .= $this->pipelineRunner->runBuild($ssh, $site, $newRelease);

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, $newRelease);
        $this->hookRunner->assertHooksSucceeded($log, 'before_activate');

        $log .= $this->anchorRunner->runActivate($ssh, $site, $newRelease, $gitSsh, $repo, $branch);
        $this->hookRunner->assertHooksSucceeded($log, 'activate');

        $currentPath = $base.'/current';
        $log .= $this->pipelineRunner->runRelease($ssh, $site, $currentPath);

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $log .= "\n--- post deploy ---\n";
            $log .= $ssh->exec(sprintf('cd %s && %s', escapeshellarg($currentPath), $post), 900);
        }

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $currentPath);
        $this->hookRunner->assertHooksSucceeded($log, 'after_activate');

        $log .= app(SupervisorDeployRestarter::class)->restartAfterDeployIfEnabled($site);

        try {
            $log .= app(AtomicDeployHealthChecker::class)->verify($site, $ssh);
        } catch (\Throwable $e) {
            $meta = is_array($site->meta) ? $site->meta : [];
            $autoRollback = (bool) ($meta['deploy_health_auto_rollback'] ?? false);
            if ($autoRollback && $previousActiveRelease !== null) {
                try {
                    $log .= "\n--- auto rollback ---\n";
                    $log .= app(SiteReleaseRollback::class)->rollbackTo($site->fresh(), $previousActiveRelease);
                } catch (\Throwable $rollbackEx) {
                    throw new \RuntimeException(
                        $e->getMessage().' '.__('Automatic rollback failed: :msg', ['msg' => $rollbackEx->getMessage()]),
                        0,
                        $rollbackEx
                    );
                }

                throw new \RuntimeException(
                    $e->getMessage().' '.__('The previous release was restored.'),
                    0,
                    $e
                );
            }

            throw $e;
        }

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

        $currentPath = trim($ssh->exec(sprintf('readlink -f %s/current 2>/dev/null || echo %s/current', $baseEsc, $baseEsc), 30));
        $syncResult = app(ByoRepoConfigSync::class)->syncAfterDeploy($site, $ssh, $currentPath !== '' ? $currentPath : $base.'/current');
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
        }

        return ['output' => $log, 'sha' => $sha !== '' ? $sha : null, 'release_folder' => $folder];
    }
}
