<?php

declare(strict_types=1);

namespace App\Services\WordPress\Advisories;

/**
 * Provider-agnostic representation of a single security advisory.
 *
 * Schema-light by design — Wordfence and Patchstack both ship rich
 * metadata, but the WP Plugins tab only renders five fields. Anything
 * beyond gets dropped at the provider layer rather than leaked here.
 */
final class Advisory
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $severity,
        public readonly ?string $cve,
        public readonly ?string $patchedVersion,
        public readonly ?string $url,
    ) {}

    /**
     * Build from a Wordfence Intelligence API record (the v1 source).
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromWordfence(array $record): self
    {
        $cves = $record['references']['cve'] ?? null;
        $cve = is_array($cves) && isset($cves[0]) ? (string) $cves[0] : null;

        $patched = $record['patched']['versions'] ?? null;
        $patchedVersion = is_array($patched) && isset($patched[0]) ? (string) $patched[0] : null;

        return new self(
            id: (string) ($record['id'] ?? ''),
            title: (string) ($record['title'] ?? 'Unknown vulnerability'),
            severity: strtolower((string) ($record['cvss']['rating'] ?? 'unknown')),
            cve: $cve,
            patchedVersion: $patchedVersion,
            url: is_string($record['references']['url'][0] ?? null) ? (string) $record['references']['url'][0] : null,
        );
    }
}
