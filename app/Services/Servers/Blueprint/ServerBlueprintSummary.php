<?php

declare(strict_types=1);

namespace App\Services\Servers\Blueprint;

use App\Support\Servers\InstalledStack;

/**
 * Human-readable summaries for blueprint tiles and workspace panels.
 */
final class ServerBlueprintSummary
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function tagline(array $snapshot): string
    {
        $stack = InstalledStack::fromArray(is_array($snapshot['stack'] ?? null) ? $snapshot['stack'] : []);

        $parts = array_values(array_filter([
            $this->labelWebserver($stack->webserver),
            $stack->phpVersion !== null ? 'PHP '.$stack->phpVersion : null,
            $this->labelDatabase($stack->database),
            $this->labelCache($stack->cacheService),
        ]));

        if ($parts === []) {
            return __('Stack snapshot from a golden server.');
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{firewall_rules: int, supervisor_programs: int, runtimes: list<string>}
     */
    public function extras(array $snapshot): array
    {
        $firewall = is_array($snapshot['firewall_rules'] ?? null) ? count($snapshot['firewall_rules']) : 0;
        $programs = is_array($snapshot['supervisor_programs'] ?? null) ? count($snapshot['supervisor_programs']) : 0;

        $runtimes = [];
        $defaults = $snapshot['runtime_defaults'] ?? [];
        if (is_array($defaults)) {
            foreach ($defaults as $runtime => $version) {
                if (is_string($runtime) && is_string($version) && $version !== '') {
                    $runtimes[] = ucfirst($runtime).' '.$version;
                }
            }
        }

        return [
            'firewall_rules' => $firewall,
            'supervisor_programs' => $programs,
            'runtimes' => $runtimes,
        ];
    }

    private function labelWebserver(?string $webserver): ?string
    {
        return match ($webserver) {
            'nginx' => 'Nginx',
            'caddy' => 'Caddy',
            'openlitespeed' => 'OpenLiteSpeed',
            default => null,
        };
    }

    private function labelDatabase(?string $database): ?string
    {
        return match ($database) {
            'mysql84' => 'MySQL 8.4',
            'mariadb114' => 'MariaDB 11.4',
            'postgres17' => 'Postgres 17',
            default => $database !== null && $database !== '' ? strtoupper($database) : null,
        };
    }

    private function labelCache(?string $cache): ?string
    {
        return match ($cache) {
            'redis' => 'Redis',
            'valkey' => 'Valkey',
            'memcached' => 'Memcached',
            default => null,
        };
    }
}
