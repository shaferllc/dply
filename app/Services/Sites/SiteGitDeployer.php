<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Services\Servers\SupervisorDeployRestarter;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
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

    public function run(Site $site, ?SiteDeployment $deployment = null): array
    {
        if (($site->deploy_strategy ?? 'simple') === 'atomic') {
            return app(AtomicSiteDeployer::class)->deploy($site, $deployment);
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

        // For HTTPS repos with no deploy key, inject the stored OAuth/PAT token
        // into the URL so git can authenticate without a TTY.
        // The authenticated URL is used only for the clone command; the raw URL
        // is what appears everywhere else (logs, UI) to avoid leaking tokens.
        if (! $privateKey && $site->user !== null && str_starts_with($repo, 'http')) {
            $provider = (string) ($site->repositoryMeta()['git_provider_kind'] ?? '');
            // Fall back to detecting the provider from the URL when not explicitly set.
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

        $log = '';
        $pathEsc = escapeshellarg($path);

        // ── CLONE ── mkdir + before/after-clone hooks + the git checkout.
        $cloneStart = microtime(true);
        $cloneLog = $ssh->exec(sprintf('mkdir -p %s', $pathEsc), 60);

        // Pre-clone snapshot: show what's already on disk before we touch it.
        $cloneLog .= $ssh->exec(sprintf(
            'echo "=== [dply] PRE-CLONE SNAPSHOT ==="; '
            .'echo "[dply] whoami=$(whoami)"; '
            .'echo "[dply] hostname=$(hostname)"; '
            .'echo "[dply] path=%1$s"; '
            .'echo "[dply] has-git-dir=$([ -d %1$s/.git ] && echo yes || echo no)"; '
            .'echo "[dply] disk:"; df -h %1$s 2>&1; '
            .'echo "[dply] ls:"; ls -la %1$s 2>&1 | head -n 50; '
            .'echo "[dply] git-remote:"; git -C %1$s remote -v 2>&1 || echo "(no remote)"; '
            .'echo "[dply] git-status:"; git -C %1$s status --short 2>&1 || echo "(not a git repo)"; '
            .'echo "=== [dply] END PRE-CLONE SNAPSHOT ==="',
            $pathEsc
        ), 30);

        $cloneLog .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'before_clone');

        $checkGit = trim($ssh->exec(sprintf('if [ -d %1$s/.git ]; then echo yes; else echo no; fi', $pathEsc), 30)) === 'yes';

        $cloneLog .= $this->anchorRunner->runClone($ssh, $site, $path, $gitSsh, $repo, $branch, false, $checkGit);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'clone');

        // Use --verify so git exits non-zero and prints nothing when HEAD is
        // unborn (no commits yet) — `git rev-parse HEAD` prints the literal
        // string "HEAD" in that case, which would fool the $sha !== '' check.
        $sha = trim($ssh->exec(sprintf('git -C %s rev-parse --verify HEAD 2>/dev/null', $pathEsc), 30));

        // Post-clone snapshot: confirm exactly what landed on disk.
        $cloneLog .= $ssh->exec(sprintf(
            'echo "=== [dply] POST-CLONE SNAPSHOT ==="; '
            .'echo "[dply] sha=%1$s"; '
            .'echo "[dply] git-log:"; git -C %2$s log --oneline -10 2>&1; '
            .'echo "[dply] git-branch:"; git -C %2$s branch -a 2>&1; '
            .'echo "[dply] git-remote:"; git -C %2$s remote -v 2>&1; '
            .'echo "[dply] git-status:"; git -C %2$s status 2>&1; '
            .'echo "[dply] composer.json=$([ -f %2$s/composer.json ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] package.json=$([ -f %2$s/package.json ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] artisan=$([ -f %2$s/artisan ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] .env.example=$([ -f %2$s/.env.example ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] ls:"; ls -la %2$s 2>&1; '
            .'echo "=== [dply] END POST-CLONE SNAPSHOT ==="',
            $sha !== '' ? $sha : '(none — clone failed)',
            $pathEsc
        ), 45);

        $cloneLog .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'after_clone');

        $log .= $cloneLog;
        // SSH exec does not surface non-zero exit codes, so a failed clone
        // (bad repo URL, unreachable host, missing/wrong deploy key, or
        // branch) would otherwise sail through as a no-op "success". An empty
        // SHA means no commit was checked out — treat that as a real failure.
        $cloneOk = $sha !== '';
        $deployment?->recordPhaseResults('clone', [[
            'step_id' => 'clone',
            'step_type' => 'clone',
            'command' => null,
            'ok' => $cloneOk,
            'output' => $cloneLog,
            'duration_ms' => (int) round((microtime(true) - $cloneStart) * 1000),
            'skipped' => false,
        ]]);
        if (! $cloneOk) {
            $hint = str_contains($log, 'could not read Username')
                ? ' The repository appears to be private — use an SSH URL (git@github.com:org/repo.git) and add a deploy key.'
                : ' Check the repository URL, branch ('.$branch.'), and deploy key.';
            throw new \RuntimeException(
                'Deploy failed: no Git checkout at '.$path.' after clone.'.$hint.' See the clone output above.'
                ."\n\n".$log
            );
        }

        // ── ENV ── compose the .env (env cache + attached resource bindings'
        // connection vars + workspace variables) and write it BEFORE build, so
        // composer/asset steps and the live app see bound resources (DB_*,
        // REDIS_*, …). The simple deployer historically skipped this entirely —
        // so attached resources (and any env held until deploy) never reached
        // the box. Mirrors AtomicSiteDeployer's pre-build env push.
        if ($server->hostCapabilities()->supportsEnvPushToHost()) {
            $envOverride = trim((string) ($site->env_file_path ?? ''));
            if ($envOverride !== '') {
                app(SiteEnvPusher::class)->push($site, $envOverride);
                $ssh->exec(sprintf('ln -sfn %s %s', escapeshellarg($envOverride), escapeshellarg($path.'/.env')), 30);
                $log .= sprintf("\n[dply] ENV → external env_file_path %s; symlinked %s/.env → it\n", $envOverride, $path);
            } else {
                app(SiteEnvPusher::class)->push($site, $path.'/.env');
                $log .= "\n[dply] ENV → composed .env (cache + connected resources) written to ".$path."/.env\n";
            }
        }

        // ── BUILD ── install deps / compile assets from the build steps.
        $build = $this->pipelineRunner->runBuild($ssh, $site, $path);
        $log .= $build['log'];
        $deployment?->recordPhaseResults('build', $build['steps']);
        if (! $build['ok']) {
            throw new \RuntimeException('Deploy failed during the build phase. See the deployment log for details.');
        }

        // ── LOGGING ── overlay dply's generated config/logging.php now that
        // vendor/ exists (the probe boots the app) and before activate, so a
        // rejected config aborts the deploy without going live. No-op unless the
        // site has a managed (v2-spec) logging binding.
        if ($server->hostCapabilities()->supportsEnvPushToHost()) {
            $log .= app(SiteLoggingConfigPusher::class)->apply($site, $ssh, $path)['log'];
        }

        // ── ACTIVATE ── before-activate hooks + the activate anchor (a no-op
        // for simple deploys, which serve straight from the repo path).
        $activateStart = microtime(true);
        $activateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, $path);
        $this->hookRunner->assertHooksSucceeded($activateLog, 'before_activate');
        $activateOut = $this->anchorRunner->runActivate($ssh, $site, $path, $gitSsh, $repo, $branch);
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

        // ── RELEASE ── release steps, then after-activate hooks, then the
        // post-deploy command (recorded as a final release step). Results are
        // recorded before any failure is raised so the timeline shows them.
        $release = $this->pipelineRunner->runRelease($ssh, $site, $path);
        $releaseLog = $release['log'];
        $releaseSteps = $release['steps'];
        $releaseOk = $release['ok'];

        $afterActivateLog = $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $path);
        $releaseLog .= $afterActivateLog;

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $postStart = microtime(true);
            $releaseLog .= "\n--- post deploy ---\n";
            $postOut = $ssh->exec(
                sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', $pathEsc, $post),
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

        // RESTART phase — user-authored restart commands (the text pipeline's
        // "Restart" block) run AFTER dply's managed restart.
        $restart = $this->pipelineRunner->runRestart($ssh, $site, $path);
        $log .= $restart['log'];
        $deployment?->recordPhaseResults('restart', $restart['steps']);
        if (! $restart['ok']) {
            throw new \RuntimeException('Deploy failed during the restart phase. See the deployment log for details.');
        }

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
