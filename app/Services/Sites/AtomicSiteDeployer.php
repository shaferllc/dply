<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteRelease;
use App\Services\Servers\SupervisorDeployRestarter;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
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

        // For HTTPS repos with no deploy key, inject the stored OAuth/PAT token.
        if (! $privateKey && $site->user !== null && str_starts_with($repo, 'http')) {
            $provider = (string) ($site->repositoryMeta()['git_provider_kind'] ?? '');
            if ($provider === '' || $provider === 'custom') {
                $provider = match (true) {
                    str_contains($repo, 'github.com') => 'github',
                    str_contains($repo, 'gitlab.com') => 'gitlab',
                    str_contains($repo, 'bitbucket.org') => 'bitbucket',
                    default => '',
                };
            }
            if ($provider !== '') {
                $identity = app(GitIdentityResolver::class)->forSite($site, $site->user, $provider);
                if ($identity !== null) {
                    $repo = app(SourceControlRepositoryBrowser::class)->authenticatedCloneUrl($identity, $repo);
                }
            }
        }

        $folder = gmdate('YmdHis');
        $releasesDir = $base.'/releases';
        $newRelease = $releasesDir.'/'.$folder;
        $currentPath = $base.'/current';

        $baseEsc = escapeshellarg($base);
        $newEsc = escapeshellarg($newRelease);

        // ── PLAN ── dump every resolved path up front so the deployment log
        // shows exactly where each phase will run. Zero-downtime (atomic)
        // deploys clone+build+release into the timestamped release directory,
        // then flip the `current` symlink to it — so artisan/migrate always run
        // against the real checked-out code, never the `current` symlink.
        $log .= "\n=== dply deploy plan (atomic / zero-downtime) ===\n";
        $log .= sprintf("[dply] site:            #%s %s\n", $site->id, $site->name);
        $log .= sprintf("[dply] server:          #%s %s\n", $server->id, $server->name);
        $log .= sprintf("[dply] repository:      %s\n", $repo);
        $log .= sprintf("[dply] branch/ref:      %s (%s)\n", $branch, $site->gitRefKind());
        $log .= sprintf("[dply] base dir:        %s\n", $base);
        $log .= sprintf("[dply] releases dir:    %s\n", $releasesDir);
        $log .= sprintf("[dply] new release:     %s\n", $newRelease);
        $log .= sprintf("[dply] current symlink: %s -> (flips to new release)\n", $currentPath);
        $log .= sprintf("[dply] build runs in:   %s\n", $newRelease);
        $log .= sprintf("[dply] release runs in: %s  (the release dir, NOT the symlink)\n", $newRelease);
        $log .= sprintf("[dply] env dir:         %s\n", $site->effectiveEnvDirectory());
        $log .= sprintf("[dply] nginx docroot:   %s\n", $site->effectiveDocumentRootForNginx());
        $log .= sprintf("[dply] worker site:     %s\n", $site->isWorkerSite() ? 'yes' : 'no');
        $log .= "===============================================\n";

        // ── CLONE ── make releases dir, checkout into the new release folder.
        $cloneStart = microtime(true);
        $log .= sprintf("\n[dply] CLONE → mkdir -p %s/releases, then clone %s\n", $base, $newRelease);
        $cloneLog = $ssh->exec("mkdir -p {$baseEsc}/releases", 60);

        // Pre-clone snapshot: show server state before any git work.
        $cloneLog .= $ssh->exec(sprintf(
            'echo "=== [dply] PRE-CLONE SNAPSHOT ==="; '
            .'echo "[dply] whoami=$(whoami)"; '
            .'echo "[dply] hostname=$(hostname)"; '
            .'echo "[dply] base=%1$s"; '
            .'echo "[dply] new-release=%2$s"; '
            .'echo "[dply] disk:"; df -h %1$s 2>&1; '
            .'echo "[dply] releases-ls:"; ls -la %1$s/releases 2>&1 | head -n 20; '
            .'echo "=== [dply] END PRE-CLONE SNAPSHOT ==="',
            $baseEsc,
            $newEsc
        ), 30);

        $previousActiveRelease = SiteRelease::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        $cloneLog .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $base);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'before_clone');

        $cloneLog .= $this->anchorRunner->runClone($ssh, $site, $newRelease, $gitSsh, $repo, $branch, true, false);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'clone');
        $this->anchorRunner->assertReleaseHasGit($ssh, $newRelease);

        // Post-clone snapshot: confirm exactly what landed in the release dir.
        $cloneSha = trim($ssh->exec(sprintf('git -C %s rev-parse --verify HEAD 2>/dev/null', $newEsc), 15));
        $cloneLog .= $ssh->exec(sprintf(
            'echo "=== [dply] POST-CLONE SNAPSHOT ==="; '
            .'echo "[dply] whoami=$(whoami)"; '
            .'echo "[dply] sha=%1$s"; '
            .'echo "[dply] git-log:"; git -C %2$s log --oneline -10 2>&1; '
            .'echo "[dply] git-branch:"; git -C %2$s branch -a 2>&1; '
            .'echo "[dply] git-remote:"; git -C %2$s remote -v 2>&1; '
            .'echo "[dply] git-status:"; git -C %2$s status 2>&1; '
            .'echo "[dply] composer.json=$([ -f %2$s/composer.json ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] package.json=$([ -f %2$s/package.json ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] artisan=$([ -f %2$s/artisan ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] .env.example=$([ -f %2$s/.env.example ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] disk:"; df -h %2$s 2>&1; '
            .'echo "[dply] ls:"; ls -la %2$s 2>&1; '
            .'echo "=== [dply] END POST-CLONE SNAPSHOT ==="',
            $cloneSha !== '' ? $cloneSha : '(none)',
            $newEsc
        ), 45);

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

        $log .= sprintf("[dply] CLONE done in %dms → %s\n", (int) round((microtime(true) - $cloneStart) * 1000), $newRelease);

        app(VmSiteComposerDetectionPersister::class)->persistFromReleasePath($site, $ssh, $newRelease);

        // ── ENV ── seed the fresh release's .env. A release is a clean git
        // checkout and .env is gitignored, so without this every build/release
        // step (and the live app) reads Laravel's defaults — e.g. migrate hits
        // pgsql 127.0.0.1:5432 and fails "Connection refused". Done BEFORE build
        // so asset/compile steps that read env work too. Runs only on hosts
        // that expose a server .env (the VM/BYO path this deployer serves).
        if ($server->hostCapabilities()->supportsEnvPushToHost()) {
            $releaseEnv = $newRelease.'/.env';
            $envOverride = trim((string) ($site->env_file_path ?? ''));
            if ($envOverride !== '') {
                // Custom env_file_path: the operator deliberately relocated .env
                // outside the docroot so the webserver can't serve it. Keep ONE
                // canonical file there (the source of truth) and symlink each
                // release's project-root .env at it — Laravel reads
                // <release>/.env, which resolves to the unservable external
                // file. Never copy the secret into the docroot.
                $log .= sprintf("\n[dply] ENV → external env_file_path; writing %s and symlinking %s → it\n", $envOverride, $releaseEnv);
                app(SiteEnvPusher::class)->push($site, $envOverride);
                $ssh->exec(sprintf('ln -sfn %s %s', escapeshellarg($envOverride), escapeshellarg($releaseEnv)), 30);
                $resolved = trim($ssh->exec(sprintf('readlink -f %s 2>/dev/null || echo "(unresolved)"', escapeshellarg($releaseEnv)), 30));
                $log .= sprintf("[dply] ENV → %s/.env → %s\n", basename($newRelease), $resolved);
            } else {
                // Default: .env lives in the project root. Write it straight
                // into the release dir (which `current` flips to), so this
                // release is self-contained.
                $log .= sprintf("\n[dply] ENV → writing composed .env to %s\n", $releaseEnv);
                app(SiteEnvPusher::class)->push($site, $releaseEnv);
                $log .= "[dply] ENV → .env written\n";
            }
        } else {
            $log .= "\n[dply] ENV → host does not expose a server .env; skipping\n";
        }

        // ── BUILD ── install deps / compile assets in the new release.
        $log .= sprintf("\n[dply] BUILD → running build-phase steps in %s\n", $newRelease);
        $build = $this->pipelineRunner->runBuild($ssh, $site, $newRelease);
        $log .= $build['log'];
        $deployment?->recordPhaseResults('build', $build['steps']);
        if (! $build['ok']) {
            throw new \RuntimeException('Deploy failed during the build phase. See the deployment log for details.');
        }

        $log .= sprintf("[dply] BUILD done → %d step(s), ok=%s\n", count($build['steps']), $build['ok'] ? 'true' : 'false');

        // ── ACTIVATE ── before-activate hooks + the atomic symlink flip.
        $activateStart = microtime(true);
        $log .= sprintf("\n[dply] ACTIVATE → before-activate hooks + flip %s -> %s\n", $currentPath, $newRelease);
        $activateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, $newRelease);
        $this->hookRunner->assertHooksSucceeded($activateLog, 'before_activate');
        $activateOut = $this->anchorRunner->runActivate($ssh, $site, $newRelease, $gitSsh, $repo, $branch);
        $activateLog .= $activateOut;
        // Confirm the flip actually landed: show what `current` resolves to now.
        $resolved = trim($ssh->exec(sprintf('readlink -f %s/current 2>/dev/null || echo "(missing)"', $baseEsc), 30));
        $activateLog .= sprintf("\n[dply] current now resolves to: %s\n", $resolved !== '' ? $resolved : '(empty)');
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

        // ── RELEASE ── run release steps in the freshly-built RELEASE dir, not
        // the `current` symlink. The flip above already points `current` at
        // this release, so this is equivalent for a healthy deploy — but it
        // does NOT depend on the symlink resolving. Running migrations/caches
        // against the real checked-out code means `php artisan …` always finds
        // `artisan`, instead of dying with "Could not open input file: artisan"
        // when `current` is a stale/placeholder directory (the failure mode for
        // first deploys and worker-host sites).
        $log .= sprintf("\n[dply] RELEASE → running release-phase steps in %s\n", $newRelease);
        $release = $this->pipelineRunner->runRelease($ssh, $site, $newRelease);
        $releaseLog = $release['log'];
        $releaseSteps = $release['steps'];
        $releaseOk = $release['ok'];
        $releaseLog .= sprintf("[dply] RELEASE steps done → %d step(s), ok=%s\n", count($release['steps']), $releaseOk ? 'true' : 'false');

        $afterActivateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $newRelease);
        $releaseLog .= $afterActivateLog;

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $postStart = microtime(true);
            $releaseLog .= sprintf("\n--- post deploy (in %s) ---\n", $newRelease);
            $postOut = $ssh->exec(
                sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', $newEsc, $post),
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

        // ── RESTART ── bring long-running processes onto the new release. dply's
        // managed restart (reload FPM / Octane, bounce Horizon + queue workers it
        // detects) runs first, then any user-authored RESTART-phase steps. Both
        // are post-activation and best-effort — the release is already live, so a
        // worker hiccup is logged on the timeline but doesn't roll back a healthy
        // deploy.
        $managedRestart = $this->pipelineRunner->runManagedRestart($ssh, $site, $newRelease);
        $log .= $managedRestart['log'];

        $userRestart = $this->pipelineRunner->runRestart($ssh, $site, $newRelease);
        $log .= $userRestart['log'];

        $restartSteps = array_merge($managedRestart['steps'], $userRestart['steps']);
        if ($restartSteps !== []) {
            $deployment?->recordPhaseResults('restart', $restartSteps);
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
