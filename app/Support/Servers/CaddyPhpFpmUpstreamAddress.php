<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Parse Caddy admin API upstream addresses for PHP-FPM unix sockets
 * (e.g. {@code unix///run/php/php8.3-fpm.sock}).
 */
final class CaddyPhpFpmUpstreamAddress
{
    public static function isPhpFpmSocket(string $address): bool
    {
        return self::phpVersionFromUpstream($address) !== null;
    }

    public static function phpVersionFromUpstream(string $address): ?string
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        if (preg_match('/php(\d+\.\d+)-fpm(?:\.sock)?/i', $address, $matches) !== 1) {
            return null;
        }

        $version = $matches[1];
        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return null;
        }

        return $version;
    }

    public static function normalizePhpVersion(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '' || preg_match('/(\d+\.\d+)/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function socketPathForVersion(string $version): string
    {
        $version = self::normalizePhpVersion($version) ?? '8.3';

        return str_replace(
            '{version}',
            $version,
            (string) config('sites.php_fpm_socket', '/run/php/php{version}-fpm.sock'),
        );
    }

    /**
     * Canonical display key for deduping Caddy admin + filesystem upstream rows.
     */
    public static function normalizeUpstreamAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }

        $version = self::phpVersionFromUpstream($address);
        if ($version !== null) {
            return 'unix///'.ltrim(self::socketPathForVersion($version), '/');
        }

        return $address;
    }

    /**
     * @param  list<string>  $installedVersionIds
     * @return array{
     *     primary: string,
     *     fallback: ?string,
     *     upstream: ?string,
     *     latest_installed: string,
     *     upstream_installed: bool,
     *     needs_config_update: bool
     * }
     */
    public static function repairPhpVersions(
        string $upstreamAddress,
        array $installedVersionIds,
        ?string $latestInstalled,
    ): array {
        $upstream = self::phpVersionFromUpstream($upstreamAddress);
        $latest = self::normalizePhpVersion($latestInstalled);
        if ($latest === null && $installedVersionIds !== []) {
            $sorted = $installedVersionIds;
            usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));
            $latest = self::normalizePhpVersion($sorted[0]);
        }
        $latest ??= '8.3';

        $upstreamInstalled = $upstream !== null
            && in_array($upstream, $installedVersionIds, true);
        $primary = $upstreamInstalled ? $upstream : $latest;
        $needsConfigUpdate = $upstream !== null && $primary !== $upstream;

        return [
            'primary' => $primary,
            'fallback' => null,
            'upstream' => $upstream,
            'latest_installed' => $latest,
            'upstream_installed' => $upstreamInstalled,
            'needs_config_update' => $needsConfigUpdate,
        ];
    }

    /**
     * Rewrite a php-FPM upstream target to the resolved installed version.
     */
    public static function rewriteUpstreamToVersion(string $upstream, string $resolvedVersion): string
    {
        $configured = self::phpVersionFromUpstream($upstream);
        $resolvedVersion = self::normalizePhpVersion($resolvedVersion) ?? '8.3';

        if ($configured === null || $configured === $resolvedVersion) {
            return $upstream;
        }

        $rewritten = preg_replace(
            '/php'.preg_quote($configured, '/').'-fpm/',
            'php'.$resolvedVersion.'-fpm',
            $upstream,
        );

        return is_string($rewritten) ? $rewritten : $upstream;
    }
}
