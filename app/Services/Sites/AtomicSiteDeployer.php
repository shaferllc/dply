<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
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

    public function deploy(Site $site, ?SiteDeployment $deployment = null): array
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

        // ── CLONE ── make releases dir, checkout into the new release folder.
        $cloneStart = microtime(true);
        $cloneLog = $ssh->exec("mkdir -p {$baseEsc}/releases", 60);

        $previousActiveRelease = SiteRelease::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        $cloneLog .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $base);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'before_clone');

        $cloneLog .= $this->anchorRunner->runClone($ssh, $site, $newRelease, $gitSsh, $repo, $branch, true, false);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'clone');
        $this->anchorRunner->assertReleaseHasGit($ssh, $newRelease);

        $cloneLog .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $newRelease);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'after_clone');

        $log .= $cloneLog;
        // assertReleaseHasGit() above throws if the checkout is missing, so
        // reaching here means clone succeeded.
        $deployment?->recordPhaseResults('clone', [[
            'step_id' => 'clone',
            'step_type' => 'clone',
            'command' => null,
            'ok' => true,
            'output' => $cloneLog,
            'duration_ms' => (int) round((microtime(true) - $cloneStart) * 1000),
            'skipped' => false,
        ]]);

        app(VmSiteComposerDetectionPersister::class)->persistFromReleasePath($site, $ssh, $newRelease);

        // ── BUILD ── install deps / compile assets in the new release.
        $build = $this->pipelineRunner->runBuild($ssh, $site, $newRelease);
        $log .= $build['log'];
        $deployment?->recordPhaseResults('build', $build['steps']);
        if (! $build['ok']) {
            throw new \RuntimeException('Deploy failed during the build phase. See the deployment log for details.');
        }

        // ── ACTIVATE ── before-activate hooks + the atomic symlink flip.
        $activateStart = microtime(true);
        $activateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, $newRelease);
        $this->hookRunner->assertHooksSucceeded($activateLog, 'before_activate');
        $activateOut = $this->anchorRunner->runActivate($ssh, $site, $newRelease, $gitSsh, $repo, $branch);
        $activateLog .= $activateOut;
        $this->hookRunner->assertHooksSucceeded($activateLog, 'activate');
        $log .= $activateLog;
        $deployment?->recordPhaseResults('activate', [[
            'step_id' => 'activate',
            'step_type' => 'activate',
            'command' => null,
            'ok' => true,
            'output' => $activateLog,
            'duration_ms' => (int) round((microtime(true) - $activateStart) * 1000),
            'skipped' => trim($activateOut) === '',
        ]]);

        // ── RELEASE ── release steps on the live path, after-activate hooks,
        // then the post-deploy command (recorded as a final release step).
        $currentPath = $base.'/current';
        $release = $this->pipelineRunner->runRelease($ssh, $site, $currentPath);
        $releaseLog = $release['log'];
        $releaseSteps = $release['steps'];
        $releaseOk = $release['ok'];

        $afterActivateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $currentPath);
        $releaseLog .= $afterActivateLog;

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $postStart = microtime(true);
            $releaseLog .= "\n--- post deploy ---\n";
            $postOut = $ssh->exec(
                sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', escapeshellarg($currentPath), $post),
                900
            );
            $releaseLog .= $postOut;
            $postOk = ! (preg_match('/DPLY_STEP_EXIT:(\d+)/', $postOut, $m) && (int) $m[1] !== 0);
            $releaseSteps[] = [
                'step_id' => 'post_deploy',
                'step_type' => 'post_deploy',
                'command' => $post,
                'ok' => $postOk,
                'output' => $postOut,
                'duration_ms' => (int) round((microtime(true) - $postStart) * 1000),
                'skipped' => false,
            ];
            $releaseOk = $releaseOk && $postOk;
        }

        $log .= $releaseLog;
        $deployment?->recordPhaseResults('release', $releaseSteps);
        $this->hookRunner->assertHooksSucceeded($afterActivateLog, 'after_activate');
        if (! $releaseOk) {
            throw new \RuntimeException('Deploy failed during the release phase. See the deployment log for details.');
        }

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
