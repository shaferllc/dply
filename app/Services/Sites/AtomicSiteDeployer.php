<?php

namespace App\Services\Sites;

use App\Enums\DeploymentMethod;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteRelease;
use App\Services\Deploy\DeployResumePlan;
use App\Services\Deploy\Manifest\SiteManifestCodeShapeSync;
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

    public function deploy(Site $site, ?SiteDeployment $deployment = null, ?DeployResumePlan $resume = null): array
    {
        // A resume re-attaches to an already-staged release and runs only the
        // phases from $resume->startFromPhase onward; a normal deploy runs every
        // phase. This gate expresses "is this phase at or after the start point".
        $shouldRun = fn (string $phase): bool => $resume === null || $resume->shouldRun($phase);

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

        // Blue-green is the atomic base layout constrained to two fixed,
        // alternating slots instead of timestamped releases: build into the IDLE
        // slot (the opposite of whatever `current` points at), health-check it,
        // then flip `current` to it. The live slot is never touched, so rollback
        // is a guaranteed one-flip back to it. Everything else (clone/build/env/
        // activate/health) is identical to atomic.
        $blueGreen = DeploymentMethod::forSite($site)->placement() === 'blue_green';
        if ($resume !== null) {
            // Resume: re-use the release the failed deploy already staged
            // instead of minting a fresh one. Resume only covers pre-cutover
            // failures, so this folder was never made live — re-running it
            // can't disturb whatever `current` is serving.
            $folder = $resume->releaseFolder;
        } elseif ($blueGreen) {
            $liveSlot = basename(trim($ssh->exec(
                sprintf('readlink -f %s/current 2>/dev/null || true', escapeshellarg($base)),
                30
            )));
            $folder = $liveSlot === 'blue' ? 'green' : 'blue';
            // The idle slot holds an older release; wipe it so the fresh clone
            // lands in a clean tree.
            $ssh->exec(sprintf('rm -rf %s/releases/%s', escapeshellarg($base), $folder), 120);
        } else {
            $folder = gmdate('YmdHis');
        }
        $releasesDir = $base.'/releases';
        $newRelease = $releasesDir.'/'.$folder;
        $currentPath = $base.'/current';

        $baseEsc = escapeshellarg($base);
        $newEsc = escapeshellarg($newRelease);

        // Persist the release folder on the deployment row as soon as it's
        // known — BEFORE build/release can throw — so a deploy that fails before
        // cutover still records which staged release a later "resume" attaches
        // to. (On a normal deploy the dir doesn't exist on disk until CLONE
        // below; this is just metadata.)
        $deployment?->update(['release_folder' => $folder]);

        // Needed by the post-activate health-check auto-rollback regardless of
        // whether CLONE runs this pass, so resolve it before the phase gate.
        $previousActiveRelease = SiteRelease::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        // Resume guard: the staged release must still exist and be non-empty.
        // A later successful deploy's prune can delete it; flipping/building
        // against a missing dir would be an outage, so fail loud and clear.
        if ($resume !== null) {
            $check = trim($ssh->exec(sprintf(
                'if [ -d %1$s ] && [ -n "$(ls -A %1$s 2>/dev/null)" ]; then echo DPLY_RESUME_OK; else echo DPLY_RESUME_MISSING; fi',
                $newEsc
            ), 30));
            if (! str_contains($check, 'DPLY_RESUME_OK')) {
                throw new \RuntimeException(sprintf(
                    'Cannot resume from the %s phase: the staged release "%s" is no longer on the server (likely pruned by a later deploy). Run a full deploy instead.',
                    $resume->startFromPhase,
                    $folder
                ));
            }
            // A post-cutover (restart) resume re-runs the live release's tail and
            // does NOT re-flip the symlink — so `current` must still point at this
            // release. If the failed deploy auto-rolled-back, it won't; refuse
            // rather than bounce workers against a release that isn't serving.
            if ($resume->startFromPhase === 'restart') {
                $currentTarget = basename(trim($ssh->exec(
                    sprintf('readlink -f %s/current 2>/dev/null || true', $baseEsc),
                    30
                )));
                if ($currentTarget !== $folder) {
                    throw new \RuntimeException(sprintf(
                        'Cannot resume from the restart phase: `current` points at "%s", not the failed release "%s" (it was likely rolled back). Run a full deploy instead.',
                        $currentTarget !== '' ? $currentTarget : '(none)',
                        $folder
                    ));
                }
            }
            $log .= sprintf(
                "\n[dply] RESUME → re-attaching to staged release %s; restarting from the %s phase (earlier phases carried forward)\n",
                $newRelease,
                $resume->startFromPhase
            );
        }

        // ── PLAN ── dump every resolved path up front so the deployment log
        // shows exactly where each phase will run. Zero-downtime (atomic)
        // deploys clone+build+release into the timestamped release directory,
        // then flip the `current` symlink to it — so artisan/migrate always run
        // against the real checked-out code, never the `current` symlink.
        $log .= sprintf("\n=== dply deploy plan (%s) ===\n", $blueGreen ? 'blue-green / zero-downtime' : 'atomic / zero-downtime');
        if ($blueGreen) {
            $log .= sprintf("[dply] blue-green slot: %s (idle slot built, then current flips to it)\n", $folder);
        }
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
        // Skipped on resume: the staged release already holds the checkout (and,
        // for a resume-from-release, the built vendor/) — re-cloning would wipe
        // it. The carried-forward clone results keep the timeline whole.
        if ($shouldRun('clone')) {
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
        }

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

        // ── SHARED STORAGE ── opt-in (Site.meta['shared_storage']): symlink the
        // release's storage/ at a persistent dir so logs/uploads/keys survive
        // across releases (parity with the hand-rolled deploy.sh, needed for
        // dply's own self-deploy). Customer sites keep per-release storage unless
        // they explicitly opt in. Default target = <project root>/shared/storage.
        $deployMeta = is_array($site->meta) ? $site->meta : [];
        if (! empty($deployMeta['shared_storage'])) {
            $sharedStorage = trim((string) ($deployMeta['shared_storage_path'] ?? ''));
            if ($sharedStorage === '') {
                $sharedStorage = dirname($newRelease, 2).'/shared/storage';
            }
            $releaseStorage = $newRelease.'/storage';
            $log .= sprintf("\n[dply] STORAGE → shared: %s → %s\n", $releaseStorage, $sharedStorage);
            $ssh->exec(sprintf(
                'mkdir -p %1$s && rm -rf %2$s && ln -sfn %1$s %2$s',
                escapeshellarg($sharedStorage),
                escapeshellarg($releaseStorage),
            ), 30);
        }

        // ── MANIFEST ── reconcile code-shape (build/release/processes) from a
        // repo dply.* BEFORE the build phase reads its steps, so a just-pushed
        // manifest takes effect on THIS deploy (gated by global.byo_repo_config).
        $manifestLog = app(SiteManifestCodeShapeSync::class)
            ->applyFromRemote($site, $ssh, $newRelease);
        if ($manifestLog !== '') {
            $log .= "\n".$manifestLog;
        }

        // ── BUILD ── install deps / compile assets in the new release.
        // Skipped only when resuming from a LATER phase (release): the build
        // already succeeded on the original attempt, so vendor/ + compiled
        // assets are intact in the staged release and rebuilding would be wasted
        // work. Resuming FROM build (the build broke) re-runs it here. The
        // carried-forward build results keep the timeline complete on skip.
        if ($shouldRun('build')) {
            $log .= sprintf("\n[dply] BUILD → running build-phase steps in %s\n", $newRelease);
            $buildProgress = $deployment
                ? static fn (array $full) => $deployment->recordPhaseResults('build', $full)
                : null;
            $build = $this->pipelineRunner->runBuild($ssh, $site, $newRelease, $buildProgress);
            $log .= $build['log'];
            // Final clean record (no running/pending placeholders) — overwrites the
            // last live snapshot with the settled step results.
            $deployment?->recordPhaseResults('build', $build['steps']);
            if (! $build['ok']) {
                throw new \RuntimeException('Deploy failed during the build phase. See the deployment log for details.');
            }

            $log .= sprintf("[dply] BUILD done → %d step(s), ok=%s\n", count($build['steps']), $build['ok'] ? 'true' : 'false');
        } else {
            $log .= sprintf("\n[dply] BUILD → skipped (resume from %s); reusing the build already staged in %s\n", $resume->startFromPhase, $newRelease);
        }

        // ── LOGGING ── overlay dply's generated config/logging.php into the new
        // release now that vendor/ is installed (the probe boots the app) and
        // BEFORE the symlink flip below — a rejected config throws here, so the
        // flip never happens and the prior release keeps serving. No-op unless
        // the site has a managed (v2-spec) logging binding.
        if ($server->hostCapabilities()->supportsEnvPushToHost()) {
            $log .= app(SiteLoggingConfigPusher::class)->apply($site, $ssh, $newRelease)['log'];
        }

        // ── RESOURCES ── verify every networked resource binding (database,
        // redis, storage, mail, …) is reachable from the box BEFORE the cutover.
        // A critical binding the server can't dial fails the deploy HERE — the
        // symlink never flips, so the prior release keeps serving and the new
        // one (which would immediately 500 on a dead DB/cache/store) never goes
        // live. Auxiliary bindings (broadcasting/logging) are probed but only
        // warn. Skipped on a post-cutover (restart) resume: the release is
        // already live, and re-gating it could fail a deploy that's serving.
        if ($shouldRun('resources')) {
            $log .= app(DeployResourceVerifier::class)->verify($site, $ssh, $deployment);
        }

        // ── RELEASE ── run release-phase steps (migrations, optimize, custom
        // commands) in the freshly-built RELEASE dir — the real checkout, never
        // the `current` symlink, so they do NOT depend on the flip resolving.
        // Crucially this runs BEFORE the activate/cutover below: the symlink flip
        // is the final, all-or-nothing gate. If a migration or any release step
        // fails we throw here and `current` is never moved — the prior release
        // keeps serving and the failed deploy changes nothing that's live.
        // (Running against the real release dir also means `php artisan …` always
        // finds `artisan`, never "Could not open input file: artisan" off a stale
        // `current` — the failure mode for first deploys and worker-host sites.)
        // Skipped on a post-cutover (restart) resume: the migrations/release
        // steps already ran and succeeded on the original attempt, and the
        // release is already live — re-running migrations here would be both
        // wasteful and unsafe. The carried-forward release results keep the
        // timeline complete.
        if ($shouldRun('release')) {
            $log .= sprintf("\n[dply] RELEASE → running release-phase steps in %s (before cutover)\n", $newRelease);
            $releaseProgress = $deployment
                ? static fn (array $full) => $deployment->recordPhaseResults('release', $full)
                : null;
            $release = $this->pipelineRunner->runRelease($ssh, $site, $newRelease, $releaseProgress);
            $releaseSteps = $release['steps'];
            $releaseLog = $release['log'];
            $releaseLog .= sprintf("[dply] RELEASE steps done → %d step(s), ok=%s\n", count($release['steps']), $release['ok'] ? 'true' : 'false');
            $log .= $releaseLog;
            $deployment?->recordPhaseResults('release', $releaseSteps);
            if (! $release['ok']) {
                throw new \RuntimeException('Deploy failed during the release phase before cutover — the previous release is still live and nothing changed. See the deployment log for details.');
            }
        } else {
            $log .= sprintf("\n[dply] RELEASE → skipped (resume from restart); migrations already ran and %s is already live\n", $newRelease);
        }

        // ── ACTIVATE ── before-activate hooks + the atomic symlink flip. Reached
        // only after build AND release steps pass, so the cutover is the final
        // action of a healthy deploy — any failure above left `current` pointing
        // at the prior release, exactly as if the deploy had never run.
        // Skipped on a post-cutover (restart) resume: `current` already points
        // at this release from the original attempt, so re-flipping is needless.
        if ($shouldRun('activate')) {
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
        } else {
            $log .= sprintf("\n[dply] ACTIVATE → skipped (resume from restart); current already points at %s\n", $newRelease);
        }

        // ── POST-ACTIVATE ── after-activate hooks + the user post-deploy command
        // run now that `current` points at the new release (they are post-cutover
        // by definition — e.g. warming the live site). A failure here still fails
        // the deploy, but the release is already live; the health-check +
        // auto-rollback below is the safety net for a bad cutover.
        //
        // These — and the RESTART steps below — are the post-cutover tail a Tier 2
        // "resume from restart" re-runs (RELEASE + ACTIVATE were skipped above).
        // The post-deploy step is recorded under the RESTART phase, its true
        // post-cutover home: a post-deploy failure then reads as a restart-phase
        // failure, so resume re-runs it WITHOUT re-migrating or re-flipping, and
        // never clobbers the carried-forward RELEASE steps.
        $postCutoverSteps = [];
        $afterActivateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $newRelease);
        $log .= $afterActivateLog;

        $postOk = true;
        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $postStart = microtime(true);
            $postOut = $ssh->exec(
                sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', $newEsc, $post),
                900
            );
            $log .= sprintf("\n--- post deploy (in %s) ---\n", $newRelease).$postOut;
            $postOk = ! (preg_match('/DPLY_STEP_EXIT:(\d+)/', $postOut, $m) && (int) $m[1] !== 0);
            $postCutoverSteps[] = [
                'step_id' => 'post_deploy',
                'step_type' => 'post_deploy',
                'command' => $post,
                'ok' => $postOk,
                'output' => $postOut,
                'duration_ms' => (int) round((microtime(true) - $postStart) * 1000),
                'skipped' => false,
            ];
            // Record incrementally so a post-deploy failure still surfaces on the
            // timeline (under Restart) before the throw below.
            $deployment?->recordPhaseResults('restart', $postCutoverSteps);
        }

        $this->hookRunner->assertHooksSucceeded($afterActivateLog, 'after_activate');
        if (! $postOk) {
            throw new \RuntimeException('Deploy failed during the post-deploy command (after cutover). See the deployment log for details.');
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

        $restartSteps = array_merge($postCutoverSteps, $managedRestart['steps'], $userRestart['steps']);
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

        // ── LAYOUT MIGRATE ── if a deploy-method switch armed an on-disk layout
        // change (e.g. flat→atomic), perform it now — AFTER activate + health
        // pass — so a failed deploy can never destroy the old layout. Best-effort:
        // the release is already live and healthy, so a cleanup hiccup is logged,
        // not fatal.
        try {
            $log .= app(SiteDeployLayoutMigrator::class)->migrateIfArmed($site, $ssh, $folder);
        } catch (\Throwable $e) {
            $log .= "\n[dply] layout migration skipped (non-fatal): ".$e->getMessage()."\n";
        }

        $sha = trim($ssh->exec(sprintf('cd %s && git rev-parse HEAD 2>/dev/null', $newEsc), 30));

        if ($blueGreen) {
            // Two-slot model: keep exactly blue + green (the live one and the
            // idle rollback target). Sweep any stray timestamped dirs left from a
            // prior atomic history so the tree stays the two pinned trees. The
            // live slot was just flipped to, so nothing here can drop it.
            $log .= "\n--- blue-green: keep blue + green, sweep strays ---\n";
            $log .= $ssh->exec(
                sprintf(
                    'find %s/releases -mindepth 1 -maxdepth 1 -type d ! -name blue ! -name green -exec rm -rf {} + 2>/dev/null; echo done',
                    $baseEsc
                ),
                120
            );
        } else {
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
        }

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
