<?php

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteRelease;
use App\Services\Sites\DeployHookRunner;
use App\Services\Sites\PipelineAnchorScriptRunner;
use App\Services\Sites\SiteDeployPipelineRunner;
use App\Services\SshConnectionFactory;

final class DockerSiteDeployer
{
    public function __construct(
        private readonly DeployHookRunner $hookRunner,
        private readonly SiteDeployPipelineRunner $pipelineRunner,
        private readonly PipelineAnchorScriptRunner $anchorRunner,
        private readonly SshConnectionFactory $sshFactory,
        private readonly DockerComposeArtifactBuilder $composeBuilder,
        private readonly DockerRuntimeDockerfileBuilder $dockerfileBuilder,
    ) {}

    /**
     * @return array{output: string, sha: ?string, compose_yaml: string, dockerfile: string}
     */
    /** @return array<string, mixed> */
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
        // Tag this release's image so it's a pinnable artifact — the basis for
        // image-digest history + one-tag rollback (see imageRepo()).
        $releaseFolder = gmdate('YmdHis');
        $imageTag = $this->imageRepo($site).':'.$releaseFolder;
        $compose = $this->composeBuilder->build($site, $imageTag, withBuild: true);
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

        $checkGit = trim($ssh->exec(sprintf('if [ -d %1$s/.git ]; then echo yes; else echo no; fi', $pathEsc), 30)) === 'yes';
        $log .= $this->anchorRunner->runClone($ssh, $site, $path, $gitSsh, $repo, $branch, false, $checkGit);
        $this->hookRunner->assertHooksSucceeded($log, 'clone');

        $sha = trim($ssh->exec(sprintf('cd %s && git rev-parse HEAD 2>/dev/null', $pathEsc), 30));
        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_CLONE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'after_clone');

        $ssh->putFile($path.'/docker-compose.dply.yml', $compose);
        $ssh->putFile($path.'/Dockerfile.dply', $dockerfile);

        $log .= "\n--- docker runtime files ---\n";
        $log .= "Wrote docker-compose.dply.yml and Dockerfile.dply\n";

        $build = $this->pipelineRunner->runBuild($ssh, $site, $path);
        $log .= $build['log'];
        if (! $build['ok']) {
            throw new \RuntimeException('Deploy failed during the build phase. See the deployment log for details.');
        }

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'before_activate');

        $post = trim((string) $site->post_deploy_command);
        if ($post !== '') {
            $log .= "\n--- post deploy ---\n";
            $log .= $ssh->exec(sprintf('cd %s && %s', $pathEsc, $post), 900);
        }

        $customActivate = trim((string) ($site->activeDeployPipeline->activate_script ?? ''));
        if ($customActivate !== '') {
            $log .= $this->anchorRunner->runActivate($ssh, $site, $path, $gitSsh, $repo, $branch);
            $this->hookRunner->assertHooksSucceeded($log, 'activate');
        } else {
            $log .= "\n--- docker compose up ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && docker compose -f docker-compose.dply.yml up -d --build 2>&1', $pathEsc),
                1800
            );
        }

        $release = $this->pipelineRunner->runRelease($ssh, $site, $path);
        $log .= $release['log'];
        if (! $release['ok']) {
            throw new \RuntimeException('Deploy failed during the release phase. See the deployment log for details.');
        }

        $log .= $this->hookRunner->runPhase($ssh, $site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $path);
        $this->hookRunner->assertHooksSucceeded($log, 'after_activate');

        // Image release history: record this tag as the active release (one
        // SiteRelease row per image, folder = the tag's timestamp), then prune
        // old images on the host so disk doesn't grow unbounded. The previous
        // rows stay as rollback targets — re-runnable by tag (see
        // DockerImageReleaseRollback).
        SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
        SiteRelease::query()->create([
            'site_id' => $site->id,
            'folder' => $releaseFolder,
            'git_sha' => $sha !== '' ? $sha : null,
            'is_active' => true,
        ]);
        $log .= $this->pruneImages($ssh, $site);

        return [
            'output' => $log,
            'sha' => $sha !== '' ? $sha : null,
            'compose_yaml' => $compose,
            'dockerfile' => $dockerfile,
            'release_folder' => $releaseFolder,
            'image_tag' => $imageTag,
        ];
    }

    /** Stable per-site image repository name. */
    public function imageRepo(Site $site): string
    {
        return 'dply-site-'.$site->id;
    }

    /**
     * Keep the newest N image tags for this site, remove the rest so the host
     * doesn't fill with old layers. Best-effort — never fails a healthy deploy.
     */
    private function pruneImages($ssh, Site $site): string
    {
        $keep = max(1, min(50, (int) ($site->releases_to_keep ?? 5)));
        $repo = escapeshellarg($this->imageRepo($site));

        return "\n--- prune old images (keep {$keep}) ---\n".$ssh->exec(
            sprintf(
                'docker images %1$s --format "{{.Tag}}" 2>/dev/null | sort -r | tail -n +%2$d '
                .'| while read -r t; do docker rmi %1$s:"$t" 2>/dev/null || true; done; echo done',
                $repo,
                $keep + 1
            ),
            120
        );
    }
}
