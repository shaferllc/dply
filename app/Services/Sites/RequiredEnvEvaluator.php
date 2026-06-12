<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Jobs\RecheckRequiredEnvJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\Site;

/**
 * Single source of truth for the "required env" deploy gate: reads the live
 * server .env, diffs it against the keys the app actually requires, and
 * records the result on the site (meta.deploy_blocked_env) so the Deploy panel
 * banner stays in sync.
 *
 * Shared by {@see RunSiteDeploymentJob} (which throws to block the
 * deploy) and {@see RecheckRequiredEnvJob} (which just re-evaluates
 * on demand, so a stale banner can clear without a full deploy). Both run in a
 * queue worker — the .env read is over SSH and must never run inline.
 */
final class RequiredEnvEvaluator
{
    public function __construct(
        private SiteEnvReader $reader,
        private DotEnvFileParser $parser,
    ) {}

    /**
     * Evaluate the gate and persist the outcome on the site. Returns the list
     * of still-missing required keys (code-sourced), or null when the gate
     * doesn't apply (unsupported host, opted out, nothing required, or the
     * server .env couldn't be read).
     *
     * @return list<array{key: string, sources: array<int, string>, required: bool, example: ?string}>|null
     */
    public function evaluateAndRecord(Site $site): ?array
    {
        $server = $site->server;
        if ($server === null || ! $server->hostCapabilities()->supportsEnvPushToHost()) {
            return null;
        }
        if (($site->meta['skip_env_gate'] ?? false) === true) {
            return null;
        }
        if (($site->envRequirements()['keys'] ?? []) === []) {
            return null;
        }

        try {
            $envRaw = $this->reader->read($site);
        } catch (\Throwable) {
            return null;
        }

        $parsed = $this->parser->parse($envRaw);
        // A key declared in the server .env counts as "set" even when blank
        // (KEY=). Laravel treats an empty env var as defined; this gate only
        // catches keys that are entirely ABSENT. (Filtering empties here was
        // the cause of false positives for present-but-blank Laravel vars like
        // MAIL_PASSWORD / REDIS_PASSWORD / SESSION_DOMAIN.)
        $present = array_map(static fn ($key): string => (string) $key, array_keys($parsed['variables']));

        $inherited = $site->workspace?->variables->pluck('env_key')->map(fn ($k) => (string) $k)->all() ?? [];

        // Strict gate: only no-default env() references (source 'code'). Keys
        // that only appear in .env.example or carry a config default are
        // advisory and never block a deploy.
        $missing = array_values(array_filter(
            $site->missingRequiredEnvKeys($present, $inherited),
            static fn (array $entry): bool => in_array('code', $entry['sources'], true),
        ));

        $meta = is_array($site->meta) ? $site->meta : [];

        if ($missing === []) {
            if (array_key_exists('deploy_blocked_env', $meta)) {
                unset($meta['deploy_blocked_env']);
                $site->forceFill(['meta' => $meta])->save();
            }

            return [];
        }

        $meta['deploy_blocked_env'] = [
            'at' => now()->toIso8601String(),
            'keys' => array_map(
                static fn (array $entry): array => ['key' => $entry['key'], 'example' => $entry['example']],
                $missing,
            ),
        ];
        $site->forceFill(['meta' => $meta])->save();

        return $missing;
    }
}
