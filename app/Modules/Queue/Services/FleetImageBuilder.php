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
use App\Services\Sites\QueueInsightsInstaller;
use App\Services\SshConnectionFactory;
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

    /**
     * Older images kept per site. More than one so a rebuild that turns out bad
     * can be rolled back to the previous tag by hand; few enough that a fleet
     * host's disk is not a customer's problem.
     */
    private const KEEP_IMAGES = 3;

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

        $this->ssh->forServer($host)->putFile(
            $workspace.'/Dockerfile.dply',
            $this->dockerfiles->build($site)."\n".$this->agentInstallStage(),
        );

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

        $this->prune($host, $site, $emit);

        $emit->success(__('Image :tag is ready on :host.', ['tag' => $tag, 'host' => (string) $host->name]), 'image');

        return $tag;
    }

    /**
     * Drop this site's older worker images from the build host.
     *
     * Every build leaves a full app image behind, so without this a busy site
     * fills a fleet host's disk — and a fleet host that runs out of disk stops
     * being able to start ANY customer's workers, not just this one's.
     *
     * `docker rmi` refuses to remove an image a container is using, so a tag
     * still backing a running worker survives on its own and needs no
     * bookkeeping here. Best-effort throughout: a successful build must not be
     * reported as a failure because cleanup could not run.
     */
    private function prune(Server $host, Site $site, ConsoleEmitter $emit): void
    {
        $repo = 'dply-fleet-'.$site->id;

        try {
            $result = $this->remote->runInlineBash(
                $host,
                'fleet-image-prune',
                sprintf(
                    'docker images %1$s --format "{{.ID}} {{.Repository}}:{{.Tag}}" 2>/dev/null | tail -n +%2$d '
                    .'| while read -r id ref; do docker rmi "$ref" >/dev/null 2>&1 || true; done; echo pruned',
                    escapeshellarg($repo),
                    self::KEEP_IMAGES + 1,
                ),
                timeoutSeconds: 300,
            );

            if ($result->exitCode === 0) {
                $emit->step('image', __('Older :repo images pruned, keeping the newest :keep.', [
                    'repo' => $repo,
                    'keep' => self::KEEP_IMAGES,
                ]));
            }
        } catch (\Throwable $e) {
            $emit->warn(__('Could not prune older images: :msg', ['msg' => Str::limit($e->getMessage(), 200)]), 'image');
        }
    }

    /**
     * The Dockerfile stage that installs the queue agent.
     *
     * Without it the image builds fine and then dies on first run: the runtime
     * executes `php artisan queue:work dply`, and the `dply` connection exists
     * only because this package's provider registers the connector. A deployed
     * site gets it from {@see QueueInsightsInstaller}; a
     * fleet image has no deploy, so it has to arrive here.
     *
     * Straight from Packagist, with the same constraint the deploy installer
     * uses. An earlier attempt shipped the package's source into the build
     * context as a path repository — unnecessary (it is public) and broken:
     * a path repo is `dev-main`, which a stock Laravel app's
     * `minimum-stability: stable` refuses, and a canonical higher-priority repo
     * masks the Packagist versions rather than falling through to them.
     *
     * `--no-scripts` so a customer's own post-install hooks do not run inside a
     * step dply added — and therefore `package:discover` has to be invoked by
     * hand. Laravel's auto-discovery is a composer script; skip it and the
     * package sits in `vendor/` with no entry in `bootstrap/cache/packages.php`,
     * its provider never boots, and the worker dies with the very error this
     * whole stage exists to prevent. Not `|| true`: an image whose discovery
     * failed is an image that cannot work, and should fail here rather than at
     * 3am on a customer's queue.
     *
     * `git config --global safe.directory` first: the context is a git checkout
     * owned by another uid inside the build, so composer's VCS probing prints
     * "does not have the correct ownership" on every package without it.
     *
     * Guarded on composer.json: a static or Node site has no composer project,
     * and the build must not fail on a step that does not apply to it.
     */
    private function agentInstallStage(): string
    {
        $package = (string) config('dply.queue_insights.package', 'dply/queue-insights');
        $constraint = (string) config('dply.queue_insights.constraint', '^1.0');

        $require = escapeshellarg($package.':'.$constraint);

        return <<<DOCKER

# dply queue agent — registers the `dply` queue connection the worker runs
# against. Without this the container starts and exits immediately with
# "Queue connection [dply] not configured".
RUN if [ -f composer.json ]; then \\
      git config --global --add safe.directory '*' || true; \\
      composer require {$require} --no-interaction --no-scripts --prefer-dist; \\
      php artisan package:discover --ansi; \\
    fi

# Re-assert ownership after composer, then drop to the user that owns the app.
# A worker container runs with `--cap-drop ALL`, which removes CAP_DAC_OVERRIDE
# — the capability that lets root ignore file permissions. Running as root then
# CANNOT write storage/logs, and the worker dies on its first log line with
# "could not be opened in append mode: Permission denied". Running as the owner
# is both the fix and the better posture.
RUN chown -R www-data:www-data /var/www/html || true
USER www-data
DOCKER;
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
