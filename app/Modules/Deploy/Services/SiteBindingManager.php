<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\Concerns\ManagesAiBindings;
use App\Modules\Deploy\Services\Concerns\ManagesBroadcastingBindings;
use App\Modules\Deploy\Services\Concerns\ManagesCacheBindings;
use App\Modules\Deploy\Services\Concerns\ManagesCaptchaBindings;
use App\Modules\Deploy\Services\Concerns\ManagesDatabaseBindings;
use App\Modules\Deploy\Services\Concerns\ManagesErrorTrackingBindings;
use App\Modules\Deploy\Services\Concerns\ManagesLoggingBindings;
use App\Modules\Deploy\Services\Concerns\ManagesMailBindings;
use App\Modules\Deploy\Services\Concerns\ManagesOauthBindings;
use App\Modules\Deploy\Services\Concerns\ManagesPaymentsBindings;
use App\Modules\Deploy\Services\Concerns\ManagesQueueBindings;
use App\Modules\Deploy\Services\Concerns\ManagesRedisBindings;
use App\Modules\Deploy\Services\Concerns\ManagesSearchBindings;
use App\Modules\Deploy\Services\Concerns\ManagesSessionBindings;
use App\Modules\Deploy\Services\Concerns\ManagesSmsBindings;
use App\Modules\Deploy\Services\Concerns\ManagesStorageBindings;
use App\Modules\Deploy\Services\Concerns\ResolvesReachableResources;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use InvalidArgumentException;

/**
 * Performs attach / provision / detach for a site's managed resource bindings.
 *
 * Each binding owns the connection variables it contributes to the deploy
 * environment (stored encrypted on {@see SiteBinding::$injected_env}); those
 * are injected at deploy time only and never written into the editable
 * Variables list. The {@see SiteResourceBindingResolver} reads these rows back
 * so the UI reflects the managed state.
 *
 * The per-resource-type logic lives in the {@see Concerns}
 * traits; this class owns the public entry points (attach / provision / detach),
 * the .env adoption flow, and the small helpers shared across binding types.
 */
class SiteBindingManager
{
    use ManagesAiBindings;
    use ManagesBroadcastingBindings;
    use ManagesCacheBindings;
    use ManagesCaptchaBindings;
    use ManagesDatabaseBindings;
    use ManagesErrorTrackingBindings;
    use ManagesLoggingBindings;
    use ManagesMailBindings;
    use ManagesOauthBindings;
    use ManagesPaymentsBindings;
    use ManagesQueueBindings;
    use ManagesRedisBindings;
    use ManagesSearchBindings;
    use ManagesSessionBindings;
    use ManagesSmsBindings;
    use ManagesStorageBindings;
    use ResolvesReachableResources;

    public function __construct(
        private readonly ServerDatabaseProvisioner $databaseProvisioner,
        private readonly ObjectStorageBucketProvisioner $bucketProvisioner,
    ) {}

    /**
     * Existing resources an operator can attach for a given binding type.
     * Shape per entry: ['id' => string, 'label' => string].
     *
     * @return list<array{id: string, label: string}>
     */
    /**
     * Server IDs whose resources this site can actually reach over the private
     * network: its own server (loopback) plus same-private-network peers. Public
     * wrapper over the reachability rule so UI surfaces (the re-point picker) can
     * scope to reachable backends instead of the whole org. Empty when the site
     * has no server.
     *
     * @return list<string>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<string>
     */
    public function reachableServerIdsForSite(Site $site): array
    {
        $server = $site->server;

        return $server === null ? [] : $this->reachableServerIds($server);
    }

    /**
     * @return list<string>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    public function attachableTargets(Site $site, string $type): array
    {
        return match ($type) {
            'database' => $this->attachableDatabases($site),
            'redis' => $this->attachableCacheServices($site),
            'broadcasting' => $this->attachableRealtimeApps($site),
            default => [],
        };
    }

    /**
     * Attach an existing resource to the site.
     *
     * @param  array<string, mixed> $params
     */
    public function attachExisting(Site $site, string $type, array $params): SiteBinding
    {
        $this->assertType($type);

        $binding = match ($type) {
            'database' => $this->attachDatabase($site, $params),
            'redis' => $this->attachRedis($site, $params),
            'queue' => $this->attachQueue($site, $params),
            'cache' => $this->attachCache($site, $params),
            'session' => $this->attachSession($site, $params),
            'storage' => $this->attachStorage($site, $params),
            'logging' => $this->attachLogging($site, $params),
            'mail' => $this->attachMail($site, $params),
            'broadcasting' => $this->attachBroadcasting($site, $params),
            'error_tracking' => $this->attachErrorTracking($site, $params),
            'ai' => $this->attachAi($site, $params),
            'captcha' => $this->attachCaptcha($site, $params),
            'sms' => $this->attachSms($site, $params),
            'search' => $this->attachSearch($site, $params),
            'payments' => $this->attachPayments($site, $params),
            'oauth' => $this->attachOauth($site, $params),
            'scheduler', 'workers' => $this->attachMarker($site, $type),
            default => throw new InvalidArgumentException(__('This binding type cannot be attached yet.')),
        };

        $this->adoptInjectedEnv($site, $binding);

        return $binding;
    }

    /**
     * Provision a brand-new resource, then attach it.
     *
     * @param  array<string, mixed> $params
     */
    public function provisionNew(Site $site, string $type, array $params): SiteBinding
    {
        $this->assertType($type);

        $binding = match ($type) {
            'database' => $this->provisionDatabase($site, $params),
            'storage' => $this->provisionBucket($site, $params),
            // Error tracking provisions only for Lookout (creates a project on
            // uselookout.app); every other provider has nothing to spin up and
            // falls back to attach inside provisionErrorTracking.
            'error_tracking' => $this->provisionErrorTracking($site, $params),
            // Redis/queue/cache/scheduler/workers have no separate resource to
            // spin up beyond what attach already wires, so provision falls back
            // to the attach path for v1 (which already adopts).
            default => $this->attachExisting($site, $type, $params),
        };

        $this->stampSetupProvenance($site, $binding);
        $this->adoptInjectedEnv($site, $binding);

        return $binding;
    }

    public function detach(SiteBinding $binding): void
    {
        // Broadcasting tears down its external infra on detach (KV record +
        // billing), but only when no other site still binds the same app.
        if ($binding->type === 'broadcasting') {
            $this->teardownBroadcasting($binding);
        }

        $binding->delete();
    }

    // ---- .env adoption ----------------------------------------------------

    /**
     * "Adopt" a freshly-connected resource's connection variables: drop any
     * matching keys from the site .env cache so the binding's values win
     * instead of a stale manual .env value overriding them. This is what makes
     * "choose a resource" actually take over DB_HOST/DB_PASSWORD/… rather than
     * sitting underneath whatever the operator typed before. The keys then
     * render as managed rows; the operator can still re-Override per key.
     *
     * @return list<string> the keys that were removed from the .env cache
     */
    private function adoptInjectedEnv(Site $site, SiteBinding $binding): array
    {
        if ($binding->status !== SiteBinding::STATUS_CONFIGURED) {
            return [];
        }

        $injected = $binding->connectionEnv();
        if ($injected === []) {
            return [];
        }

        $parser = app(DotEnvFileParser::class);
        $parsed = $parser->parse((string) ($site->env_file_content ?? ''));

        // Keys to pull out of the loose .env: the ones this binding injects,
        // plus any extra keys the binding type fully OWNS. For mail that's the
        // whole MAIL_* namespace + provider keys — so attaching a Mailgun
        // binding also clears a previous SMTP scaffold's MAIL_HOST/PORT/etc.
        // rather than leaving stale, now-ignored rows behind.
        $toRemove = array_unique([...array_keys($injected), ...$this->ownedEnvKeys($binding)]);

        $removed = [];
        foreach ($toRemove as $key) {
            $key = (string) $key;
            if (array_key_exists($key, $parsed['variables'])) {
                unset($parsed['variables'][$key], $parsed['comments'][$key]);
                $removed[] = $key;
            }
        }

        if ($removed !== []) {
            $site->forceFill([
                'env_file_content' => app(DotEnvFileWriter::class)->render($parsed['variables'], $parsed['comments']),
                'env_cache_origin' => 'local-edit',
            ])->save();
        }

        return $removed;
    }

    /**
     * Re-adopt every attached binding's keys out of the site's .env cache. A
     * sync-from-server rewrites the cache with the raw server .env, which
     * re-introduces keys an attached binding owns (REDIS_*, MAIL_*, DB_*, …) as
     * loose editable rows — even though the binding injects them at deploy. This
     * strips them back out so they stay managed under their resource instead of
     * bouncing into the variables list after each sync. Call it right after the
     * synced content is written.
     *
     * @return list<string> every key removed across all bindings
     */
    /** @return array<string, mixed> */
    /**
     * @return list<string>
     */
    public function reAdoptAll(Site $site): array
    {
        $removed = [];
        // adoptInjectedEnv mutates + saves $site in place, so each iteration sees
        // the trimmed content from the previous one.
        foreach ($site->loadMissing('bindings')->bindings as $binding) {
            $removed = [...$removed, ...$this->adoptInjectedEnv($site, $binding)];
        }

        return array_values(array_unique($removed));
    }

    /**
     * Extra .env keys a binding type fully OWNS beyond the ones it injects, so
     * attaching it cleans stale loose vars out of the editable list (the binding
     * becomes the single source of truth). Only mail claims a namespace today.
     *
     * AWS_* is deliberately excluded for `ses` — those keys are shared with the
     * object-storage binding, so the mail binding must not strip them.
     *
     * @return list<string>
     */
    private function ownedEnvKeys(SiteBinding $binding): array
    {
        return match ($binding->type) {
            'mail' => [
                'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
                'MAIL_SCHEME', 'MAIL_ENCRYPTION', 'MAIL_URL', 'MAIL_EHLO_DOMAIN',
                'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
                'MAILGUN_DOMAIN', 'MAILGUN_SECRET', 'MAILGUN_ENDPOINT',
                'POSTMARK_TOKEN', 'POSTMARK_MESSAGE_STREAM_ID',
                'RESEND_KEY',
            ],
            // Only the PRIMARY storage disk (s3) owns FILESYSTEM_DISK — attaching
            // it sets the disk to s3, so the loose default (local) should be
            // cleared. Additional named disks (AWS_<DISK>_*) don't touch the
            // default disk, so they own none of the loose env.
            'storage' => (((array) $binding->config)['disk'] ?? 's3') === 's3' ? ['FILESYSTEM_DISK'] : [],
            // Broadcasting fully owns BROADCAST_CONNECTION — the binding is the
            // single source of truth for the driver, so a loose copy is stale.
            'broadcasting' => ['BROADCAST_CONNECTION'],
            // Error tracking owns every provider's key namespace, so switching
            // providers (or attaching one) clears stale loose keys from another.
            'error_tracking' => [
                'SENTRY_LARAVEL_DSN', 'SENTRY_TRACES_SAMPLE_RATE',
                'BUGSNAG_API_KEY', 'FLARE_KEY',
                // Lookout owns its DSN + quick-start flag, plus the split-form
                // keys the SDK also accepts, so switching providers clears them.
                'LOOKOUT_DSN', 'LOOKOUT_LARAVEL',
                'LOOKOUT_INGEST_URL', 'LOOKOUT_PROJECT_API_KEY',
                'LOOKOUT_BASE_URI', 'LOOKOUT_API_KEY',
            ],
            // AI owns every provider's key namespace, so switching providers
            // clears the previous provider's loose key.
            'ai' => [
                'OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'ANTHROPIC_API_KEY',
                'GEMINI_API_KEY', 'GROQ_API_KEY', 'MISTRAL_API_KEY',
            ],
            'captcha' => $this->captchaOwnedEnvKeys(),
            'sms' => $this->smsOwnedEnvKeys(),
            'search' => $this->searchOwnedEnvKeys(),
            'payments' => $this->paymentsOwnedEnvKeys(),
            'oauth' => $this->oauthOwnedEnvKeys(),
            default => [],
        };
    }

    // ---- shared helpers ---------------------------------------------------

    /**
     * Stamp provenance on a resource provisioned while the site is still in its
     * first-deploy setup. These are REAL infra (a database, a bucket) created
     * BEFORE any deploy — if the operator abandons setup they become orphans
     * with no deployed app. The flag + timestamp let a reporter surface them for
     * review. We only tag the binding config; tearing the resource down is a
     * separate, explicit action (managed databases are unlinked, never
     * auto-dropped — see {@see \App\Support\Sites\SiteRelationPurger}).
     */
    private function stampSetupProvenance(Site $site, SiteBinding $binding): void
    {
        if (! $site->isInFirstDeploySetup()) {
            return;
        }

        $cfg = ($binding->config );
        if (($cfg['provisioned_during_setup'] ?? false) === true) {
            return;
        }

        $cfg['provisioned_during_setup'] = true;
        $cfg['provisioned_during_setup_at'] = now()->toIso8601String();
        $binding->forceFill(['config' => $cfg])->save();
    }

    /**
     * Guard a driver-style binding (queue/cache/session) against a missing
     * dependency. The `redis` driver only injects QUEUE_CONNECTION/CACHE_STORE/
     * SESSION_DRIVER=redis — the actual REDIS_HOST/PORT/PASSWORD come from a
     * Redis binding. Without one, the config saves, the app boots, then dies at
     * runtime the first time it touches the store. Block it up front with a
     * message that says what to do instead. (We only enforce Redis: a database
     * connection commonly already exists via server defaults or loose DB_* env,
     * so requiring a database binding would false-positive too often.)
     */
    private function assertDriverDependency(Site $site, string $resource, string $driver): void
    {
        if ($driver !== 'redis') {
            return;
        }
        if (! $site->bindings()->where('type', 'redis')->exists()) {
            throw new InvalidArgumentException(__(
                'Attach a Redis resource before setting :resource to the redis driver — Redis supplies REDIS_HOST and the connection credentials.',
                ['resource' => $resource],
            ));
        }
    }

    private function attachMarker(Site $site, string $type): SiteBinding
    {
        return $this->persist($site, $type, [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $type,
            'target_type' => null,
            'target_id' => null,
            'injected_env' => [],
            'config' => [],
        ]);
    }

    /**
     * Upsert a binding row. By default the natural key is (site_id, type) — one
     * binding per type per site. The storage type passes a wider key including
     * `name` (the disk slug) so a site can hold several object-storage buckets,
     * each its own filesystem disk; every other caller keeps the narrow key and
     * is therefore unaffected.
     *
     * @param  array<string, mixed> $attributes
     * @param  array<string, mixed> $matchOn  Attribute keys (from $attributes, plus the
     *                                 implicit site_id/type) to match the existing row on.
     */
    private function persist(Site $site, string $type, array $attributes, array $matchOn = []): SiteBinding
    {
        $key = ['site_id' => $site->id, 'type' => $type];
        foreach ($matchOn as $attr) {
            if (in_array($attr, ['site_id', 'type'], true)) {
                continue;
            }
            $key[$attr] = $attributes[$attr] ?? null;
        }

        return SiteBinding::query()->updateOrCreate($key, $attributes);
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, SiteBinding::TYPES, true)) {
            throw new InvalidArgumentException(__('Unknown binding type.'));
        }
    }
}
