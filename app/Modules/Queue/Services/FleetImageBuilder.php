<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\DockerRuntimeDockerfileBuilder;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\Runtimes\FleetHostAllocator;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SshConnectionFactory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turn a site's repository into an image a fleet worker can run.
 *
 * This is what makes managed workers available to a site dply deploys onto a
 * VM. Such a site has no container image anywhere — it is nginx, PHP-FPM and
 * supervisor on a box — so "bring your own image" excludes exactly the
 * customers the managed product is for.
 *
 * Built ON the fleet host, and deliberately not pushed. A registry is a
 * stateful service on the critical path of every worker start, and it buys
 * nothing until there is a second host to move an image between; the runtime's
 * `docker image inspect` gate exists so a locally built tag runs without one.
 * When a second host arrives, this grows a push and nothing else changes.
 *
 * The Dockerfile is {@see DockerRuntimeDockerfileBuilder}'s — the same one the
 * Docker deploy runtime writes. One definition of "this site as a container",
 * so a worker cannot drift from what a deploy would produce.
 */
class FleetImageBuilder
{
    /**
     * Long enough for a cold `composer install` plus `npm ci` on a first build.
     * Layer cache makes every later build a fraction of this.
     */
    private const BUILD_TIMEOUT = 1800;

    public function __construct(
        private readonly FleetHostAllocator $allocator,
        private readonly DockerRuntimeDockerfileBuilder $dockerfiles,
        private readonly ExecuteRemoteTaskOnServer $remote,
        private readonly SshConnectionFactory $ssh,
    ) {}

    /**
     * Build the fleet's image and return the tag.
     *
     * @throws RuntimeException when the fleet has no site, or the build fails
     */
    public function build(ManagedQueueFleet $fleet, ?ConsoleEmitter $emit = null): string
    {
        $emit ??= new ConsoleEmitter;
        $site = $this->siteFor($fleet);

        $repository = trim((string) $site->git_repository_url);

        if ($repository === '') {
            throw new RuntimeException('This site has no Git repository to build an image from.');
        }

        // Sized for the build, not the worker: a `composer install` needs far
        // more headroom than the 256 MiB a flex worker runs in, and allocating
        // at the worker's size would put builds on a host that cannot run them.
        $host = $this->allocator->allocate(2048);

        $emit->step('image', __('Building on :host …', ['host' => (string) $host->name]));

        $workspace = '/var/lib/dply/fleet-builds/'.$site->id;
        $sha = $this->checkout($host, $site, $workspace, $repository, $emit);

        $tag = $this->tagFor($site, $sha);

        $this->ssh->forServer($host)->putFile($workspace.'/Dockerfile.dply', $this->dockerfiles->build($site));

        $emit->step('image', __('docker build :tag …', ['tag' => $tag]));

        $result = $this->remote->runInlineBash(
            $host,
            'fleet-image-build',
            sprintf(
                'cd %s && docker build -f Dockerfile.dply -t %s . 2>&1',
                escapeshellarg($workspace),
                escapeshellarg($tag),
            ),
            timeoutSeconds: self::BUILD_TIMEOUT,
        );

        if ($result->exitCode !== 0) {
            throw new RuntimeException('docker build failed: '.Str::limit(trim($result->buffer), 600));
        }

        $emit->success(__('Image :tag is ready on :host.', ['tag' => $tag, 'host' => (string) $host->name]), 'image');

        return $tag;
    }

    /**
     * Clone or update the repository on the build host.
     *
     * Returns the commit that was built, which is what makes the tag mean
     * something: two builds of the same commit produce the same tag, so a
     * rebuild that changed nothing does not churn every running worker.
     */
    private function checkout(Server $host, Site $site, string $workspace, string $repository, ConsoleEmitter $emit): string
    {
        $branch = $site->git_branch ?: 'main';
        $keyPath = '/root/.ssh/dply_fleet_'.$site->id;
        $shell = $this->ssh->forServer($host);

        $gitSsh = '';

        if ($site->git_deploy_key_private) {
            $shell->putFile($keyPath, (string) $site->git_deploy_key_private);
            $shell->exec('chmod 600 '.escapeshellarg($keyPath));
            $gitSsh = 'export GIT_SSH_COMMAND='.escapeshellarg(
                'ssh -i '.$keyPath.' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new'
            ).' && ';
        }

        $emit->step('image', __('Fetching :repo (:branch) …', ['repo' => $repository, 'branch' => $branch]));

        $path = escapeshellarg($workspace);

        $this->remote->runInlineBash(
            $host,
            'fleet-image-checkout',
            implode("\n", [
                sprintf('mkdir -p %s', $path),
                sprintf(
                    '%sif [ -d %s/.git ]; then cd %s && git fetch --depth 1 origin %s && git reset --hard FETCH_HEAD; '
                    .'else git clone --depth 1 --branch %s %s %s; fi',
                    $gitSsh,
                    $path,
                    $path,
                    escapeshellarg($branch),
                    escapeshellarg($branch),
                    escapeshellarg($repository),
                    $path,
                ),
            ]),
            timeoutSeconds: 600,
        );

        $sha = trim($this->remote->runInlineBash(
            $host,
            'fleet-image-sha',
            sprintf('cd %s && git rev-parse HEAD 2>/dev/null || true', $path),
            timeoutSeconds: 60,
        )->buffer);

        // SSH exec does not surface exit codes, so a dead deploy key or a bad
        // branch would otherwise sail into `docker build` and fail there with a
        // misleading error about a missing Dockerfile. No commit, no checkout.
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new RuntimeException(
                'No Git checkout after clone. Check the repository URL, the branch ('.$branch.') and the deploy key.'
            );
        }

        return $sha;
    }

    /** Distinct from the deploy runtime's `dply-site-*` tags, which are that host's. */
    private function tagFor(Site $site, string $sha): string
    {
        return 'dply-fleet-'.$site->id.':'.substr($sha, 0, 12);
    }

    private function siteFor(ManagedQueueFleet $fleet): Site
    {
        $namespace = $fleet->namespace;
        $site = $namespace instanceof QueueNamespace ? $namespace->site : null;

        if (! $site instanceof Site) {
            throw new RuntimeException(
                'This queue is not attached to a site dply deploys, so there is no repository to build from. '
                .'Set a worker image manually instead.'
            );
        }

        return $site;
    }
}
