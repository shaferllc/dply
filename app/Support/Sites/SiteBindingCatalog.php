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
 * (attached = configured card, unattached = "add" ghost card), VM-first with a
 * per-type runtime filter, and a light env-keys hint on each card.
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
     * @return array<string, array{group: string, label: string, icon: string, purpose: string, env: list<string>, runtimes: list<string>, needs?: list<string>}>
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
                'group' => 'data', 'label' => 'Redis', 'icon' => 'heroicon-o-bolt',
                'purpose' => 'In-memory store for cache, queues and sessions.',
                'env' => ['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'],
                'runtimes' => ['vm'],
            ],
            'cache' => [
                'group' => 'data', 'label' => 'Cache', 'icon' => 'heroicon-o-square-3-stack-3d',
                'purpose' => 'Choose the cache store Laravel uses.',
                'env' => ['CACHE_STORE'],
                'runtimes' => ['vm'], 'needs' => ['redis'],
            ],
            'queue' => [
                'group' => 'data', 'label' => 'Queue', 'icon' => 'heroicon-o-queue-list',
                'purpose' => 'Choose the queue connection for background jobs.',
                'env' => ['QUEUE_CONNECTION'],
                'runtimes' => ['vm'], 'needs' => ['redis'],
            ],
            'session' => [
                'group' => 'data', 'label' => 'Sessions', 'icon' => 'heroicon-o-key',
                'purpose' => 'Where sessions are stored, plus cookie behaviour.',
                'env' => ['SESSION_DRIVER'],
                'runtimes' => ['vm'], 'needs' => ['redis'],
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
            'publication' => [
                'group' => 'runtime', 'label' => 'Publication', 'icon' => 'heroicon-o-newspaper',
                'purpose' => 'Runtime-managed publication target.',
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
                'binding' => $binding,
                'attached' => $binding instanceof SiteBinding,
                // Storage is multi-instance: a site can attach several buckets,
                // each its own filesystem disk. The card renders the full list;
                // every other type stays single (`bindings` is null for them).
                'bindings' => $type === 'storage'
                    ? $bindings->filter(fn (SiteBinding $b) => $b->type === 'storage')->values()
                    : null,
            ];
        }

        // Drop groups that ended up empty for this runtime.
        return array_filter($out, fn ($g) => $g['types'] !== []);
    }
}
