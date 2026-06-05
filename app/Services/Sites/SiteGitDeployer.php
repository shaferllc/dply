<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
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

        $log = '';
        $pathEsc = escapeshellarg($path);

        // ── CLONE ── mkdir + before/after-clone hooks + the git checkout.
        $cloneStart = microtime(true);
        $cloneLog = $ssh->exec(sprintf('mkdir -p %s', $pathEsc), 60);
        $cloneLog .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_BEFORE_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'before_clone');

        $checkGit = trim($ssh->exec(sprintf('if [ -d %1$s/.git ]; then echo yes; else echo no; fi', $pathEsc), 30)) === 'yes';

        $cloneLog .= $this->anchorRunner->runClone($ssh, $site, $path, $gitSsh, $repo, $branch, false, $checkGit);
        $this->hookRunner->assertHooksSucceeded($cloneLog, 'clone');

        $sha = trim($ssh->exec(sprintf('cd %s && git rev-parse HEAD 2>/dev/null', $pathEsc), 30));

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
            throw new \RuntimeException(sprintf(
                'Deploy failed: no Git checkout at %s after clone. Check the repository URL, branch (%s), and deploy key — see the clone output above.%s',
                $path,
                $branch,
                "\n\n".$log
            ));
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
