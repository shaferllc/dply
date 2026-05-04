<?php

declare(strict_types=1);

namespace App\Services\WordPress\Advisories;

/**
 * Pluggable interface for plugin / theme / core security-advisory data.
 *
 * Q20: v1 ships {@see WordfenceIntelligenceProvider} (free tier, no
 * key, 24h cache). v2 swaps can include Patchstack or a Wordfence
 * Premium tier with an API key — same interface, different concrete.
 *
 * Returned advisories are deliberately schema-light so consumers
 * (the Plugins tab in PR 10, the audit/log surfaces, the API) can
 * render uniformly without each provider's metadata leaking up.
 */
interface AdvisoryProvider
{
    /**
     * Look up open vulnerabilities for a single plugin slug + currently-installed version.
     *
     * @return list<Advisory>
     */
    public function forPlugin(string $slug, string $installedVersion): array;

    /**
     * Look up open vulnerabilities for a single theme slug + version.
     *
     * @return list<Advisory>
     */
    public function forTheme(string $slug, string $installedVersion): array;

    /**
     * Look up open vulnerabilities for a WordPress core version.
     *
     * @return list<Advisory>
     */
    public function forCore(string $installedVersion): array;

    /**
     * Provider attribution — surfaced in advisory drill-down modals
     * so the operator can trace the data source ("Wordfence", "Patchstack").
     */
    public function name(): string;
}
