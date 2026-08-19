<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployment;

/**
 * Picks a catalog PHP version (8.4, 8.5, …) when Composer or the repo pin
 * requires a newer runtime than the site is on.
 */
final class PhpVersionUpgradePlanner
{
    /**
     * Target catalog id to install/switch to, or null when the site already
     * satisfies the requirement (or we cannot tell).
     */
    public static function targetForSite(Site $site, ?string $failureText = null): ?string
    {
        if ($site->runtimeKey() !== null && $site->runtimeKey() !== 'php') {
            return null;
        }

        $text = $failureText ?? self::latestFailedDeployText($site);
        $required = self::requiredVersion($text, $site);
        if ($required === null) {
            return null;
        }

        $target = self::catalogVersionFor($required);
        if ($target === null) {
            return null;
        }

        $current = self::normalizeMajorMinor($site->phpVersion() ?? self::currentFromOutput($text));
        if ($current !== null && version_compare($current, $target, '>=')) {
            return null;
        }

        return $target;
    }

    /**
     * Highest PHP version Composer (or the repo pin) asked for.
     */
    public static function requiredVersion(?string $failureText, ?Site $site = null): ?string
    {
        $fromOutput = self::requiredFromOutput((string) $failureText);
        $fromDetection = $site instanceof Site ? self::requiredFromDetection($site) : null;

        return self::maxVersion($fromOutput, $fromDetection);
    }

    public static function requiredFromOutput(string $text): ?string
    {
        if ($text === '' || ! preg_match('/does not satisfy that requirement|your php version \(/i', $text)) {
            return null;
        }

        $found = [];
        if (preg_match_all('/requires php\s+([^\s,;]+)/i', $text, $matches) >= 1) {
            foreach ($matches[1] as $constraint) {
                $version = self::minimumFromConstraint((string) $constraint);
                if ($version !== null) {
                    $found[] = $version;
                }
            }
        }

        return self::maxVersion(...$found);
    }

    public static function currentFromOutput(string $text): ?string
    {
        if (preg_match('/your php version\s+\(([^)]+)\)/i', $text, $match) === 1) {
            return self::normalizeMajorMinor($match[1]);
        }

        return null;
    }

    public static function minimumFromConstraint(string $constraint): ?string
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return null;
        }

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    public static function catalogVersionFor(string $required): ?string
    {
        $needed = self::normalizeMajorMinor($required);
        if ($needed === null) {
            return null;
        }

        foreach (self::releasedCatalogIds() as $id) {
            if (version_compare($id, $needed, '>=')) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function releasedCatalogIds(): array
    {
        $ids = [];
        foreach ((array) config('server_provision_options.php_versions', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || $id === 'none') {
                continue;
            }

            $label = strtolower((string) ($row['label'] ?? ''));
            if (str_contains($label, 'not yet released')) {
                continue;
            }

            $ids[$id] = $id;
        }

        $ids = array_values($ids);
        usort($ids, static fn (string $a, string $b): int => version_compare($a, $b));

        return $ids;
    }

    public static function normalizeMajorMinor(?string $version): ?string
    {
        if ($version === null || trim($version) === '') {
            return null;
        }

        if (preg_match('/(\d+)\.(\d+)/', $version, $match) !== 1) {
            return null;
        }

        return $match[1].'.'.$match[2];
    }

    private static function requiredFromDetection(Site $site): ?string
    {
        $candidates = [
            data_get($site->meta, 'vm_runtime.detected.version'),
            data_get($site->meta, 'vm_runtime.detected.php'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $version = self::minimumFromConstraint($candidate);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    private static function latestFailedDeployText(Site $site): ?string
    {
        $deployment = $site->latestDeployment();
        if (! $deployment instanceof SiteDeployment || $deployment->status !== SiteDeployment::STATUS_FAILED) {
            return null;
        }

        $parts = [(string) $deployment->log_output];
        $phaseResults = is_array($deployment->phase_results ?? null) ? $deployment->phase_results : [];
        array_walk_recursive($phaseResults, static function ($value) use (&$parts): void {
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        });

        $text = trim(implode("\n", $parts));

        return $text !== '' ? $text : null;
    }

    private static function maxVersion(?string ...$versions): ?string
    {
        $best = null;
        foreach ($versions as $version) {
            if ($version === null || $version === '') {
                continue;
            }
            if ($best === null || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }

        return $best;
    }
}
