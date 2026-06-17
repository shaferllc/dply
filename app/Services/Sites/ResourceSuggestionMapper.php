<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Maps a site's scanned environment variables to the managed resource bindings
 * it most likely needs, so the first-deploy setup wizard can suggest "connect a
 * database / Redis / object storage / mail / broadcasting" instead of leaving
 * the operator to hand-fill DB_* / REDIS_* / AWS_* by hand.
 *
 * Detection is a deliberately small, deterministic KEY-PATTERN MAP — the env
 * scanner ({@see SiteEnvRequirementScanner}) already produces the key list, and
 * this turns it into binding suggestions. Two pattern sets per resource:
 *
 *   - trigger: the strong signal that the app actually wants this resource. The
 *     suggestion only appears when a scanned key matches a trigger (REDIS_* for
 *     Redis, AWS_BUCKET/FILESYSTEM_DISK for storage, …). Keeps us from
 *     suggesting Redis just because SESSION_DRIVER exists with its default.
 *   - owned: every key the resulting binding manages. Used for dual-path
 *     SATISFACTION — a detected resource is satisfied either by connecting a
 *     binding OR by every owned key it matched being set, so we don't nag when
 *     the operator supplied the connection by hand.
 *
 * Scope is intentionally the setup-time set agreed for onboarding: database,
 * redis (which also covers cache/queue/session), storage, mail, broadcasting.
 * Observability and commerce/auth resources stay on the site tabs and never
 * interrupt the wizard. The map is the single source of truth so the standalone
 * Environment / Resources surfaces can reuse it without drifting.
 */
class ResourceSuggestionMapper
{
    /**
     * The ordered key-pattern map. One entry per setup-time resource.
     *
     * @return list<array{
     *     type: string,
     *     label: string,
     *     icon: string,
     *     default_mode: 'attach'|'provision',
     *     satisfying_types: list<string>,
     *     trigger: list<string>,
     *     owned: list<string>,
     *     headline: string,
     *     description: string,
     *     note: ?string,
     * }>
     */
    /** @return array<string, mixed> */
    /**
     * @return array<string, mixed>
     */
    public function map(): array
    {
        return [
            [
                'type' => 'database',
                'label' => __('Database'),
                'icon' => 'heroicon-o-circle-stack',
                'default_mode' => 'provision',
                'satisfying_types' => ['database'],
                'trigger' => ['DB_', 'DATABASE_URL'],
                'owned' => ['DB_', 'DATABASE_URL'],
                'headline' => __('Provision a database'),
                'description' => __('Create a fresh database on this server with generated credentials, or attach an existing one.'),
                'note' => null,
            ],
            [
                'type' => 'redis',
                'label' => __('Redis'),
                'icon' => 'heroicon-o-bolt',
                'default_mode' => 'attach',
                // Connecting Redis also satisfies the cache / queue / session
                // driver bindings that point at it.
                'satisfying_types' => ['redis', 'cache', 'queue', 'session'],
                // Only REDIS_* is a strong enough trigger — CACHE_STORE /
                // QUEUE_CONNECTION / SESSION_DRIVER ship in every Laravel app
                // with non-redis defaults, so they only count as OWNED.
                'trigger' => ['REDIS_'],
                'owned' => ['REDIS_', 'CACHE_STORE', 'CACHE_DRIVER', 'CACHE_PREFIX', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'SESSION_CONNECTION'],
                'headline' => __('Connect Redis'),
                'description' => __('Attach this server\'s Redis (installed if needed). Use it for cache, sessions, and the queue in one step.'),
                'note' => null,
            ],
            [
                'type' => 'storage',
                'label' => __('Object storage'),
                'icon' => 'heroicon-o-archive-box',
                'default_mode' => 'provision',
                'satisfying_types' => ['storage'],
                // AWS_BUCKET / FILESYSTEM_DISK / S3_ specifically indicate object
                // storage rather than SES mail (which also uses AWS_ keys).
                'trigger' => ['AWS_BUCKET', 'FILESYSTEM_DISK', 'S3_'],
                'owned' => ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_URL', 'AWS_ENDPOINT', 'AWS_USE_PATH_STYLE_ENDPOINT', 'FILESYSTEM_DISK', 'S3_'],
                'headline' => __('Provision a bucket'),
                'description' => __('Create an object-storage bucket on your own cloud account, or attach an existing one.'),
                'note' => null,
            ],
            [
                'type' => 'mail',
                'label' => __('Mail'),
                'icon' => 'heroicon-o-envelope',
                'default_mode' => 'attach',
                'satisfying_types' => ['mail'],
                'trigger' => ['MAIL_'],
                'owned' => ['MAIL_'],
                'headline' => __('Set up mail'),
                'description' => __('Defaults to the log driver so the app boots — add a real provider (Mailgun, Postmark, SES, Resend, SMTP) whenever you\'re ready.'),
                'note' => __('Boot-safe by default — no provider credentials required to deploy.'),
            ],
            [
                'type' => 'broadcasting',
                'label' => __('Broadcasting'),
                'icon' => 'heroicon-o-signal',
                'default_mode' => 'attach',
                'satisfying_types' => ['broadcasting'],
                'trigger' => ['PUSHER_', 'REVERB_', 'ABLY_KEY', 'BROADCAST_CONNECTION'],
                'owned' => ['PUSHER_', 'REVERB_', 'ABLY_KEY', 'BROADCAST_', 'VITE_PUSHER_', 'VITE_REVERB_'],
                'headline' => __('Configure broadcasting'),
                'description' => __('Wire up real-time websockets. Connecting the managed relay is billable, so it is never provisioned automatically.'),
                'note' => __('Never auto-provisions paid infrastructure — you confirm the tier explicitly.'),
            ],
        ];
    }

    /**
     * Suggested resources for a site, derived from its scanned env requirements.
     * Each suggestion carries the keys it matched (for dual-path satisfaction)
     * and the default attach/provision mode the wizard should open the modal in.
     *
     * @return array<int, array<string, array|string|null>>
     *     type: string,
     *     label: string,
     *     icon: string,
     *     default_mode: 'attach'|'provision',
     *     satisfying_types: list<string>,
     *     headline: string,
     *     description: string,
     *     note: ?string,
     *     matched_keys: list<string>,
     * }>
     */
    /** @return array<string, mixed> */
    public function forSite(Site $site): array
    {
        $keys = [];
        foreach (($site->envRequirements()['keys'] ?? []) as $entry) {
            if (($entry) && ($k = (string) ($entry['key'] ?? '')) !== '') {
                $keys[] = $k;
            }
        }

        return $this->forKeys($keys);
    }

    /**
     * Pure mapping from a flat list of env keys to suggestions. Exposed
     * separately so callers with keys in hand (or tests) skip the model.
     *
     * @param  array<string, mixed> $keys
     * @return list<array<string, mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, mixed>>
     * @param  array<string, mixed> $keys
     */
    public function forKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));

        $suggestions = [];
        foreach ($this->map() as $resource) {
            // Triggered only when a scanned key matches a strong signal.
            $triggered = false;
            foreach ($keys as $key) {
                if ($this->keyMatches($key, $resource['trigger'])) {
                    $triggered = true;
                    break;
                }
            }
            if (! $triggered) {
                continue;
            }

            $matched = array_values(array_filter(
                $keys,
                fn (string $key): bool => $this->keyMatches($key, $resource['owned']),
            ));

            $suggestions[] = [
                'type' => $resource['type'],
                'label' => $resource['label'],
                'icon' => $resource['icon'],
                'default_mode' => $resource['default_mode'],
                'satisfying_types' => $resource['satisfying_types'],
                'headline' => $resource['headline'],
                'description' => $resource['description'],
                'note' => $resource['note'],
                'matched_keys' => $matched,
            ];
        }

        return $suggestions;
    }

    /**
     * True when $key equals an exact pattern or starts with a prefix pattern.
     * Patterns ending in `_` are treated as prefixes; everything else is exact.
     *
     * @param  array<string, mixed> $keys
     * @param  array<string, mixed> $patterns
     */
    private function keyMatches(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '_')) {
                if (str_starts_with($key, $pattern)) {
                    return true;
                }
            } elseif ($key === $pattern) {
                return true;
            }
        }

        return false;
    }
}
