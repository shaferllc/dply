<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteSecretResidency;
use RuntimeException;

/**
 * Resolves a site's non-resident secrets — the env vars whose real value is NOT
 * stored as plaintext-under-APP_KEY in dply's database — into their actual
 * values, just-in-time, at the single env-materialization chokepoint
 * ({@see SiteEnvPusher::push()}).
 *
 * The loose `.env` carries only `${dply:secret:<id>}` placeholders for these
 * keys (see {@see SiteSecretResidency::placeholder()}); this resolver swaps each
 * placeholder for the resolved value before the file is rendered and pushed.
 *
 * No-op for the overwhelming common case: a site with no residency rows has no
 * placeholders, so {@see resolve()} returns the map untouched. The per-mode
 * resolution backends land in later PRs (escrow → PR1/PR2, external → PR3/PR4);
 * the match arms below are the seams they fill.
 */
class SecretResidencyResolver
{
    private const PLACEHOLDER_MARKER = '${dply:secret:';

    /**
     * @param  array<string, string>  $vars  the merged env map (loose + bindings)
     * @param  string|null  $ephemeralIdentity  a customer-held age identity supplied
     *   for THIS push only and never persisted (Tier 2b). Null for every other tier.
     * @return array<string, string>
     */
    public function resolve(Site $site, array $vars, ?string $ephemeralIdentity = null): array
    {
        if (! $this->hasPlaceholder($vars)) {
            return $vars;
        }

        $byPlaceholder = $site->secretResidencies()
            ->get()
            ->keyBy(fn (SiteSecretResidency $r): string => $r->placeholder());

        $resolved = [];
        foreach ($vars as $key => $value) {
            $resolved[$key] = (is_string($value) && $byPlaceholder->has($value))
                ? $this->resolveOne($byPlaceholder->get($value), $ephemeralIdentity)
                : $value;
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function hasPlaceholder(array $vars): bool
    {
        foreach ($vars as $value) {
            if (is_string($value) && str_contains($value, self::PLACEHOLDER_MARKER)) {
                return true;
            }
        }

        return false;
    }

    private function resolveOne(SiteSecretResidency $residency, ?string $ephemeralIdentity): string
    {
        return match ($residency->mode) {
            SiteSecretResidency::MODE_ESCROW => throw new RuntimeException(
                "Secret '{$residency->key}' uses escrow residency, which is not enabled yet."
            ),
            SiteSecretResidency::MODE_EXTERNAL => throw new RuntimeException(
                "Secret '{$residency->key}' uses external-store residency, which is not enabled yet."
            ),
            default => throw new RuntimeException(
                "Unknown secret residency mode '{$residency->mode}' for '{$residency->key}'."
            ),
        };
    }
}
