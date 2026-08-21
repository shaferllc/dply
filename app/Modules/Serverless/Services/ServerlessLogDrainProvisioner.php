<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ships a managed function's application log to dply's own drain.
 *
 * ## The gap this closes
 *
 * A `logging` binding delivers its environment through `SiteEnvPusher` on VM
 * sites and `EdgeDplyResourceResolver` on Edge. A function receives environment
 * through neither — its only channel is
 * {@see ServerlessEnvironmentPreparer::mergeKeys()}, and every caller of that
 * was a serverless-specific provisioner (database, cache, queue, app bucket).
 * So drains simply did not reach functions, which is why
 * `SiteBindingCatalog` scopes the logging type to `['vm']` and the function's
 * environment defaults to `LOG_CHANNEL=stderr`.
 *
 * ## Why this is worth more than parity
 *
 * Without a drain, a function's application log is only readable through the
 * `LogsPanel` Runtime tab, which flattens log lines off the last 50
 * invocations — no search, no level filter, and
 * `serverless:prune-invocations` drops organic rows after 7 days.
 *
 * Pointed at the dply drain, the same lines land in `app_logs`, where
 * `SiteAppLogs` filters by level and searches the message, with 30-day
 * retention and a per-site ingest rate limit. Turning this on is therefore also
 * how a function gets searchable, longer-lived logs.
 *
 * ## Cost, stated plainly
 *
 * The drain is syslog-over-TLS. A function has no resident process to hold the
 * socket open, so it reconnects per invocation — real per-request latency, paid
 * on every request rather than amortised as it would be on a VM. That is why
 * this is opt-in rather than on by default.
 */
class ServerlessLogDrainProvisioner
{
    public const PROVIDER = 'dply_realtime';

    /**
     * Env keys the drain owns, removed on disable so the Environment panel
     * does not keep showing a dead endpoint.
     */
    private const OWNED_KEYS = [
        'PAPERTRAIL_URL',
        'PAPERTRAIL_PORT',
        'DPLY_LOG_DRAIN_HOST',
        'DPLY_LOG_DRAIN_PORT',
    ];

    public function __construct(
        private readonly SiteBindingManager $bindings,
        private readonly ServerlessEnvironmentPreparer $environment,
    ) {}

    /**
     * True when the operator has configured a drain endpoint for this
     * installation. Without one there is nothing to point a function at.
     */
    public function isAvailable(): bool
    {
        return trim((string) config('log_drains.dply_realtime.host', '')) !== ''
            && trim((string) config('log_drains.dply_realtime.port', '')) !== '';
    }

    public function binding(Site $site): ?SiteBinding
    {
        return $site->bindings()
            ->where('type', 'logging')
            ->where('target_type', 'log_drain')
            ->first();
    }

    public function isEnabled(Site $site): bool
    {
        return $this->binding($site) instanceof SiteBinding;
    }

    /**
     * Attach the drain and put its variables into the function's managed
     * environment. Takes effect on the next deploy, when that environment is
     * bundled into the artifact.
     */
    public function enable(Site $site): SiteBinding
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('No dply log drain endpoint is configured for this installation.');
        }

        $binding = $this->bindings->attachExisting($site, 'logging', [
            'provider' => self::PROVIDER,
        ]);

        $this->inject($site, $binding);

        Log::info('serverless.log_drain.enabled', ['site_id' => $site->id]);

        return $binding;
    }

    public function disable(Site $site): void
    {
        $binding = $this->binding($site);
        if ($binding instanceof SiteBinding) {
            $this->bindings->detach($binding);
        }

        // Back to the platform default rather than leaving the function
        // pointed at an endpoint that no longer accepts it. stderr is what
        // prepare() would have set: the function filesystem is read-only
        // except /tmp, so a file channel would fail trying to mkdir.
        $this->environment->mergeKeys($site, ['LOG_CHANNEL' => 'stderr']);
        $this->environment->forgetKeys($site, self::OWNED_KEYS);

        Log::info('serverless.log_drain.disabled', ['site_id' => $site->id]);
    }

    /**
     * Re-assert the drain's variables at deploy time.
     *
     * Idempotent and cheap. It exists so an environment edited by hand — or one
     * that predates the binding — heals on the next deploy instead of silently
     * logging to stderr while the workspace claims a drain is attached.
     */
    public function sync(Site $site): bool
    {
        $binding = $this->binding($site);
        if (! $binding instanceof SiteBinding) {
            return false;
        }

        $this->inject($site, $binding);

        return true;
    }

    private function inject(Site $site, SiteBinding $binding): void
    {
        $env = $binding->connectionEnv();
        if ($env === []) {
            return;
        }

        // Merged BEFORE prepare() runs, which is what makes this work:
        // prepare() only defaults LOG_CHANNEL to stderr when the key is
        // absent, so a drain set here is left alone rather than overwritten.
        $this->environment->mergeKeys($site, $env);
    }
}
