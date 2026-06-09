<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Resolves the candidate sources a site can seed its .env from before its first
 * deploy (pool workers / same-repo sites / any org site), and sanitizes a copied
 * .env so secrets and host-specific values don't leak between sites.
 *
 * Sanitize policy (chosen 2026-06-04): keep every KEY so the operator sees what
 * to fill, but blank known-secret / host-bound values and regenerate APP_KEY —
 * a safe starting template rather than a verbatim secret copy.
 */
final class EnvImportSources
{
    /**
     * Value blanked on import: the key carries over, the value is cleared so the
     * operator must set it for this site. APP_KEY is handled separately (regen).
     */
    private const SECRET_PATTERNS = [
        '/(_PASSWORD|_SECRET|_TOKEN|_KEY|_PASS|_DSN|_CREDENTIALS)$/i',
        '/^(DB|DATABASE|REDIS|MAIL|AWS|MEILISEARCH|PUSHER|REVERB|STRIPE|TWILIO)_/i',
    ];

    /**
     * Keys that survive sanitization even though they match a pattern above —
     * they're config, not secrets, and a sensible default to carry over.
     */
    private const KEEP = [
        'DB_CONNECTION', 'DB_PORT', 'REDIS_PORT', 'MAIL_MAILER', 'MAIL_PORT',
        'AWS_DEFAULT_REGION', 'REVERB_SCHEME', 'REVERB_PORT',
    ];

    /**
     * Candidate sources for $site, grouped. Only sites with a stored .env and a
     * different id are eligible. A site may appear in more than one group; the UI
     * shows the most specific group first.
     *
     * @return array{workers: array<int, array<string, mixed>>, same_repo: array<int, array<string, mixed>>, org: array<int, array<string, mixed>>}
     */
    public static function candidatesFor(Site $site): array
    {
        $orgId = $site->organization_id;
        $repo = trim((string) $site->git_repository_url);

        $eligible = Site::query()
            ->where('organization_id', $orgId)
            ->whereKeyNot($site->getKey())
            ->whereNotNull('env_file_content')
            ->where('env_file_content', '!=', '')
            ->with('server')
            ->get();

        $row = fn (Site $s): array => [
            'id' => (string) $s->id,
            'label' => $s->name,
            'server' => $s->server?->name,
        ];

        // Worker pool members of this site's server (they share one env).
        $poolId = $site->server?->worker_pool_id;
        $workers = $poolId
            ? $eligible->filter(fn (Site $s): bool => $s->server?->worker_pool_id === $poolId)->map($row)->values()->all()
            : [];

        $sameRepo = $repo !== ''
            ? $eligible->filter(fn (Site $s): bool => trim((string) $s->git_repository_url) === $repo)->map($row)->values()->all()
            : [];

        return [
            'workers' => $workers,
            'same_repo' => $sameRepo,
            'org' => $eligible->map($row)->values()->all(),
        ];
    }

    /**
     * Sanitize a copied env variable map: blank known secrets / host-bound values
     * (keeping the key), regenerate APP_KEY, and leave plain config intact.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function sanitize(array $variables): array
    {
        $out = [];
        foreach ($variables as $key => $value) {
            $k = (string) $key;
            if ($k === 'APP_KEY') {
                $out[$k] = 'base64:'.base64_encode(random_bytes(32));

                continue;
            }
            $out[$k] = self::isSecret($k) ? '' : (string) $value;
        }

        return $out;
    }

    /**
     * Whether a key holds a secret/host-bound value (public accessor for callers
     * that need to mask a single value, e.g. the per-variable import picker).
     */
    public static function isSecretKey(string $key): bool
    {
        return self::isSecret($key);
    }

    private static function isSecret(string $key): bool
    {
        if (in_array($key, self::KEEP, true)) {
            return false;
        }
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
