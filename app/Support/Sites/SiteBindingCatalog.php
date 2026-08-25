<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\SiteBinding;
use Illuminate\Support\Collection;

/**
 * Describes the palette for the site Resources hub — the grouped, full set of
 * binding types a site can have, with the metadata the hub renders (group,
 * icon, one-line purpose, the env keys each typically injects, and which
 * runtimes it applies to). The hub is a view over this + the site's actual
 * {@see SiteBinding} rows; this class is the single source of truth for what's
 * offered and how it's grouped, mirroring the LoggingChannelCatalog pattern.
 *
 * Decisions baked in (see the site-resources-hub memory): grouped full palette
 * (attached = configured card; unattached types live only in a single global
 * "Add resource" dropdown, grouped by category, rather than a wall of ghost
 * cards), VM-first with a per-type runtime filter, and a light env-keys hint.
 */
final class SiteBindingCatalog
{
    /** Ordered groups: key => human label. */
    public const GROUPS = [
        'data' => 'Data & cache',
        'delivery' => 'Delivery & comms',
        'integrations' => 'Integrations',
        'runtime' => 'Runtime',
    ];

    /**
     * Per-type metadata, keyed by SiteBinding type. `runtimes` is the set of
     * site runtimes the type applies to ('vm' today; 'edge' added later when
     * Edge adopts a curated subset). `env` is an illustrative hint of the keys
     * the binding injects (the real keys live on each binding's injected_env).
     *
     * @return array<string, array{group: string, label: string, icon: string, purpose: string, env: list<string>, runtimes: list<string>, needs?: list<string>, needsAny?: list<string>}>
     */
    public static function types(): array
    {
        return [
            'database' => [
                'group' => 'data', 'label' => 'Database', 'icon' => 'heroicon-o-circle-stack',
                'purpose' => 'Attach or provision a MySQL/Postgres database.',
                'env' => ['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
                'runtimes' => ['vm'],
            ],
            'redis' => [
                'group' => 'data', 'label' => 'Redis / Valkey', 'icon' => 'heroicon-o-bolt',
                'purpose' => 'In-memory store for cache, queues and sessions (Redis protocol).',
                'env' => ['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'],
                'runtimes' => ['vm'],
            ],
            'cache' => [
                'group' => 'data', 'label' => 'Cache', 'icon' => 'heroicon-o-square-3-stack-3d',
                'purpose' => 'Choose the cache store Laravel uses.',
                'env' => ['CACHE_STORE'],
                'runtimes' => ['vm'], 'needsAny' => ['redis', 'database'],
            ],
            'queue' => [
                'group' => 'data', 'label' => 'Queue', 'icon' => 'heroicon-o-queue-list',
                'purpose' => 'Choose the queue connection for background jobs.',
                'env' => ['QUEUE_CONNECTION'],
                'runtimes' => ['vm'], 'needsAny' => ['redis', 'database'],
            ],
            'session' => [
                'group' => 'data', 'label' => 'Sessions', 'icon' => 'heroicon-o-key',
                'purpose' => 'Where sessions are stored, plus cookie behaviour.',
                'env' => ['SESSION_DRIVER'],
                'runtimes' => ['vm'], 'needsAny' => ['redis', 'database'],
            ],
            'storage' => [
                'group' => 'data', 'label' => 'Object storage', 'icon' => 'heroicon-o-archive-box',
                'purpose' => 'S3-compatible bucket for the filesystem disk.',
                'env' => ['FILESYSTEM_DISK', 'AWS_BUCKET', 'AWS_ACCESS_KEY_ID'],
                'runtimes' => ['vm'],
            ],
            'mail' => [
                'group' => 'delivery', 'label' => 'Mail', 'icon' => 'heroicon-o-envelope',
                'purpose' => 'Outbound email transport (SMTP, Mailgun, SES…).',
                'env' => ['MAIL_MAILER', 'MAIL_FROM_ADDRESS'],
                'runtimes' => ['vm'],
            ],
            'broadcasting' => [
                'group' => 'delivery', 'label' => 'Broadcasting', 'icon' => 'heroicon-o-signal',
                'purpose' => 'Realtime websockets (dply relay, Reverb, or BYO).',
                'env' => ['BROADCAST_CONNECTION'],
                'runtimes' => ['vm'],
            ],
            'logging' => [
                'group' => 'delivery', 'label' => 'Logging', 'icon' => 'heroicon-o-clipboard-document-list',
                'purpose' => 'Channels, drains and the dply Realtime log stream.',
                'env' => ['LOG_CHANNEL'],
                'runtimes' => ['vm'],
            ],
            'error_tracking' => [
                'group' => 'delivery', 'label' => 'Error tracking', 'icon' => 'heroicon-o-bug-ant',
                'purpose' => 'Report exceptions to Sentry, Bugsnag or Flare.',
                'env' => ['SENTRY_LARAVEL_DSN'],
                'runtimes' => ['vm'],
            ],
            'ai' => [
                'group' => 'integrations', 'label' => 'AI / LLM', 'icon' => 'heroicon-o-sparkles',
                'purpose' => 'Provider API key for OpenAI, Anthropic, Gemini…',
                'env' => ['OPENAI_API_KEY'],
                'runtimes' => ['vm'],
            ],
            'captcha' => [
                'group' => 'integrations', 'label' => 'CAPTCHA', 'icon' => 'heroicon-o-shield-check',
                'purpose' => 'reCAPTCHA, Turnstile or hCaptcha keys (+ Vite mirror).',
                'env' => ['TURNSTILE_SITE_KEY'],
                'runtimes' => ['vm'],
            ],
            'sms' => [
                'group' => 'integrations', 'label' => 'SMS / push', 'icon' => 'heroicon-o-chat-bubble-left-right',
                'purpose' => 'Twilio, Vonage or FCM notification channel keys.',
                'env' => ['TWILIO_SID'],
                'runtimes' => ['vm'],
            ],
            'search' => [
                'group' => 'data', 'label' => 'Search', 'icon' => 'heroicon-o-magnifying-glass',
                'purpose' => 'Laravel Scout driver — Algolia, Meilisearch or Typesense.',
                'env' => ['SCOUT_DRIVER'],
                'runtimes' => ['vm'],
            ],
            'payments' => [
                'group' => 'integrations', 'label' => 'Payments', 'icon' => 'heroicon-o-credit-card',
                'purpose' => 'Stripe or Paddle keys (Cashier) + webhook endpoint.',
                'env' => ['STRIPE_KEY'],
                'runtimes' => ['vm'],
            ],
            'oauth' => [
                'group' => 'integrations', 'label' => 'OAuth login', 'icon' => 'heroicon-o-finger-print',
                'purpose' => 'Socialite client keys with an auto-filled redirect URL.',
                'env' => ['GITHUB_CLIENT_ID'],
                'runtimes' => ['vm'],
            ],
            'connected_app' => [
                'group' => 'integrations', 'label' => 'Connected apps', 'icon' => 'heroicon-o-puzzle-piece',
                'purpose' => 'Slack, Discord, Telegram, Google Drive, Dropbox keys for the app.',
                'env' => ['SLACK_BOT_TOKEN', 'GOOGLE_DRIVE_CLIENT_ID'],
                'runtimes' => ['vm'],
            ],
            // Not a binding: the CDN's state lives in Site.meta['cdn'] behind its
            // own page, and this entry deep-links there the way scheduler and
            // workers do. It sits in delivery because that is where someone
            // looks for "what serves my traffic" — the edge is in front of the
            // webserver, so it belongs beside mail and the rest of delivery
            // rather than buried in a networking sub-page nobody opens.
            'cdn' => [
                'group' => 'delivery', 'label' => 'CDN / Edge', 'icon' => 'heroicon-o-globe-alt',
                'purpose' => 'Put Cloudflare in front of this site — cache, purge and edge TLS.',
                'env' => [],
                'runtimes' => ['vm'],
            ],
            'scheduler' => [
                'group' => 'runtime', 'label' => 'Scheduler', 'icon' => 'heroicon-o-clock',
                'purpose' => 'Run the Laravel scheduler (cron) for this site.',
                'env' => [],
                'runtimes' => ['vm'],
            ],
            'workers' => [
                'group' => 'runtime', 'label' => 'Workers', 'icon' => 'heroicon-o-cpu-chip',
                'purpose' => 'Queue worker / Horizon processes for this site.',
                'env' => [],
                'runtimes' => ['vm'],
            ],
            // Not operator-attachable: the deploy runtime writes this into
            // Site.meta['runtime_target']['publication'] and owns it from
            // there ({@see \App\Modules\Deploy\Services\SiteResourceBindingResolver}).
            // The purpose line says what it *is* rather than who manages it —
            // "runtime-managed publication target" told a reader nothing.
            'publication' => [
                'group' => 'runtime', 'label' => 'Publication', 'icon' => 'heroicon-o-newspaper',
                'purpose' => 'The URL this site is served at — filled in by the runtime on deploy.',
                'env' => [],
                'runtimes' => ['vm'],
            ],
        ];
    }

    /**
     * The catalog grouped for rendering, filtered to a runtime, with each type's
     * currently-attached binding (or null) resolved from the site's rows.
     *
     * @param  Collection<int, SiteBinding>  $bindings
     * @return array<string, array{label: string, types: list<array<string, mixed>>}>
     */
    public static function grouped(string $runtime, $bindings): array
    {
        $out = [];
        foreach (self::GROUPS as $groupKey => $groupLabel) {
            $out[$groupKey] = ['label' => $groupLabel, 'types' => []];
        }

        foreach (self::types() as $type => $meta) {
            if (! in_array($runtime, $meta['runtimes'], true)) {
                continue;
            }
            $binding = $bindings->first(fn (SiteBinding $b) => $b->type === $type);
            $out[$meta['group']]['types'][] = [
                'type' => $type,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'purpose' => $meta['purpose'],
                'env' => $meta['env'],
                'needs' => $meta['needs'] ?? [],
                'needsAny' => $meta['needsAny'] ?? [],
                'binding' => $binding,
                'attached' => $binding instanceof SiteBinding,
                // Multi-instance types (storage, database, …) can hold several
                // rows per site — each a distinct instance (a bucket/disk, a DB
                // connection). The hub renders the full list; single types stay
                // one row (`bindings` is null for them).
                'bindings' => SiteBinding::isMultiInstance($type)
                    ? $bindings->filter(fn (SiteBinding $b) => $b->type === $type)->values()
                    : null,
            ];
        }

        // Drop groups that ended up empty for this runtime.
        return array_filter($out, fn ($g) => $g['types'] !== []);
    }

    /** Whether the site already has an attached binding of this type. */
    public static function hasAttachedType(Collection $bindings, string $type): bool
    {
        return $bindings->contains(fn (SiteBinding $b) => $b->type === $type);
    }

    /**
     * True when a type that declares `needsAny` is missing every listed
     * dependency (e.g. queue with neither Redis nor a database).
     *
     * @param  list<string>  $needsAny
     */
    public static function missingNeedsAny(Collection $bindings, array $needsAny): bool
    {
        if ($needsAny === []) {
            return false;
        }

        foreach ($needsAny as $need) {
            if (self::hasAttachedType($bindings, $need)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a Laravel driver option (redis / database) has a matching
     * attached resource. File, cookie, array, and other local stores are
     * always available.
     */
    public static function driverStoreAvailable(Collection $bindings, string $driver): bool
    {
        return match ($driver) {
            'redis' => self::hasAttachedType($bindings, 'redis'),
            'database' => self::hasAttachedType($bindings, 'database'),
            default => true,
        };
    }
}
