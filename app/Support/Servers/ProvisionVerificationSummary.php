<?php

namespace App\Support\Servers;

use App\Models\ServerProvisionArtifact;

class ProvisionVerificationSummary
{
    /**
     * @return list<array{key:string,label:string,status:string,detail:?string}>
     */
    public static function fromArtifact(?ServerProvisionArtifact $artifact): array
    {
        if (! $artifact) {
            return [];
        }

        $checks = $artifact->metadata['checks'] ?? null;
        if (! is_array($checks) || $checks === []) {
            $decoded = json_decode((string) $artifact->content, true);
            $checks = is_array($decoded) ? $decoded : [];
        }

        return array_values(array_filter(array_map(function (mixed $check): ?array {
            if (! is_array($check)) {
                return null;
            }

            $key = trim((string) ($check['key'] ?? ''));
            $status = trim((string) ($check['status'] ?? 'unknown'));
            $detail = trim((string) ($check['detail'] ?? ''));

            if ($key === '') {
                return null;
            }

            return [
                'key' => $key,
                'label' => self::labelForKey($key),
                'status' => $status,
                'detail' => $detail !== '' ? $detail : null,
            ];
        }, $checks)));
    }

    public static function labelForKey(string $key): string
    {
        return match ($key) {
            'php' => 'PHP CLI',
            'php-fpm' => 'PHP-FPM service',
            'nginx' => 'Nginx config test',
            'caddy' => 'Caddy config test',
            'apache' => 'Apache config test',
            'mysql' => 'MySQL service',
            'postgresql' => 'PostgreSQL service',
            'redis' => 'Redis ping',
            'valkey' => 'Valkey ping',
            'haproxy' => 'HAProxy config test',
            'docker' => 'Docker CLI',
            'docker-daemon' => 'Docker daemon',
            'ufw' => 'Firewall status',
            default => ucwords(str_replace(['-', '_'], ' ', $key)),
        };
    }
}
