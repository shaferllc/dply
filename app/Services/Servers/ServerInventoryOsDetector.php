<?php

namespace App\Services\Servers;

/**
 * Maps /etc/os-release style content to {@see config('server_settings.os_versions')} keys.
 */
class ServerInventoryOsDetector
{
    /**
     * @return array{key: string|null, pretty: string|null}
     */
    public static function fromOsRelease(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['key' => null, 'pretty' => null];
        }

        $vars = self::parseOsRelease($raw);
        $pretty = $vars['PRETTY_NAME'] ?? null;
        if ($pretty !== null) {
            $pretty = trim($pretty, '"');
        }

        $id = strtolower((string) ($vars['ID'] ?? ''));
        $versionId = trim((string) ($vars['VERSION_ID'] ?? ''), '"');
        $codename = strtolower((string) ($vars['VERSION_CODENAME'] ?? ''));

        $key = match (true) {
            $id === 'ubuntu' => self::mapUbuntu($versionId),
            $id === 'debian' => self::mapDebian($versionId, $codename),
            $id === 'rocky' => str_starts_with($versionId, '9') ? 'rocky-9' : null,
            $id === 'almalinux' => str_starts_with($versionId, '9') ? 'almalinux-9' : null,
            default => null,
        };

        return ['key' => $key, 'pretty' => $pretty];
    }

    /**
     * @return array<string, string>
     */
    private static function parseOsRelease(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }

        return $out;
    }

    private static function mapUbuntu(string $versionId): ?string
    {
        if (str_starts_with($versionId, '22.')) {
            return 'ubuntu-22-04';
        }
        if (str_starts_with($versionId, '24.')) {
            return 'ubuntu-24-04';
        }

        return null;
    }

    private static function mapDebian(string $versionId, string $codename): ?string
    {
        if ($versionId === '12' || $codename === 'bookworm') {
            return 'debian-12';
        }
        if ($versionId === '13' || $codename === 'trixie') {
            return 'debian-13';
        }

        return null;
    }
}
