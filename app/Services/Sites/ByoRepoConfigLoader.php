<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Services\Edge\Config\EdgeRepoConfig;
use App\Services\Edge\Config\EdgeRepoConfigLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses shared dply.yaml sections for BYO VM sites — reuses Edge routing
 * normalization and adds BYO-specific cron commands + deploy hook scripts.
 */
final class ByoRepoConfigLoader
{
    public const MANAGED_HOOK_PREFIX = "# @dply-managed dply.yaml\n";

    public function __construct(
        private EdgeRepoConfigLoader $edgeLoader,
    ) {}

    /**
     * @return array{
     *     config: EdgeRepoConfig,
     *     crons: list<array{schedule: string, command: string}>,
     *     deploy_hooks: list<array{phase: string, script: string, timeout: int, sort_order: int}>,
     *     warnings: list<string>
     * }
     */
    public function parse(string $sourcePath, string $raw): array
    {
        $config = $this->edgeLoader->parse($sourcePath, $raw);
        $parsed = $this->decodeRoot($sourcePath, $raw);
        $warnings = $config->warnings;

        return [
            'config' => $config,
            'crons' => $this->parseByoCrons(is_array($parsed) ? $parsed : [], $warnings),
            'deploy_hooks' => $this->parseDeployHooks(is_array($parsed) ? $parsed : [], $warnings),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeRoot(string $sourcePath, string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (str_ends_with(strtolower($sourcePath), '.json') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        try {
            $decoded = Yaml::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $warnings
     * @return list<array{schedule: string, command: string}>
     */
    private function parseByoCrons(array $parsed, array &$warnings): array
    {
        $value = $parsed['crons'] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('crons[%d] must be a map for BYO sites.', $index);

                continue;
            }

            $schedule = is_string($entry['schedule'] ?? null) ? trim($entry['schedule']) : '';
            $command = is_string($entry['command'] ?? null) ? trim($entry['command']) : '';
            if ($schedule === '' || $command === '') {
                if ($schedule !== '' && $command === '') {
                    $warnings[] = sprintf('crons[%d] needs `command` for BYO server cron sync.', $index);
                }

                continue;
            }

            $out[] = [
                'schedule' => $schedule,
                'command' => $command,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $warnings
     * @return list<array{phase: string, script: string, timeout: int, sort_order: int}>
     */
    private function parseDeployHooks(array $parsed, array &$warnings): array
    {
        $value = $parsed['deploy_hooks'] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $allowedPhases = ['before_clone', 'after_clone', 'after_activate'];
        $out = [];

        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('deploy_hooks[%d] must be a map.', $index);

                continue;
            }

            $phase = is_string($entry['phase'] ?? null) ? trim($entry['phase']) : '';
            if (! in_array($phase, $allowedPhases, true)) {
                $warnings[] = sprintf('deploy_hooks[%d].phase must be one of: %s.', $index, implode(', ', $allowedPhases));

                continue;
            }

            $script = is_string($entry['script'] ?? null) ? trim($entry['script']) : '';
            if ($script === '') {
                $warnings[] = sprintf('deploy_hooks[%d].script is required.', $index);

                continue;
            }

            $timeout = 900;
            if (isset($entry['timeout'])) {
                if (! is_int($entry['timeout']) || $entry['timeout'] < 30) {
                    $warnings[] = sprintf('deploy_hooks[%d].timeout must be an integer ≥ 30.', $index);
                } else {
                    $timeout = min($entry['timeout'], 3600);
                }
            }

            $sortOrder = isset($entry['sort_order']) && is_int($entry['sort_order']) ? max(0, $entry['sort_order']) : $index;

            $out[] = [
                'phase' => $phase,
                'script' => self::MANAGED_HOOK_PREFIX.$script,
                'timeout' => $timeout,
                'sort_order' => $sortOrder,
            ];
        }

        return $out;
    }
}
