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
     *     crons: list<array{schedule: string, command: string, user: ?string}>,
     *     server_crons: list<array{schedule: string, command: string, user: ?string}>,
     *     deploy_hooks: list<array{phase: string, script: string, timeout: int, sort_order: int}>,
     *     env_declarations: list<array{name: string, required: bool, description: ?string, default: ?string}>,
     *     warnings: list<string>
     * }
     */
    /** @return array<string, mixed> */
    public function parse(string $sourcePath, string $raw): array
    {
        $config = $this->edgeLoader->parse($sourcePath, $raw);
        $decoded = $this->decodeRoot($sourcePath, $raw);
        $parsed = is_array($decoded) ? $decoded : [];
        $warnings = $config->warnings;

        return [
            'config' => $config,
            'crons' => $this->parseCronBlock($parsed, 'crons', 'BYO site cron', $warnings),
            'server_crons' => $this->parseCronBlock($parsed, 'server_crons', 'server cron', $warnings),
            'deploy_hooks' => $this->parseDeployHooks($parsed, $warnings),
            'env_declarations' => $this->parseEnvDeclarations($parsed, $warnings),
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
     * @param  array<string, mixed> $parsed
     * @param  array<string, mixed> $warnings
     * @return list<array{schedule: string, command: string, user: ?string}>
     */
    private function parseCronBlock(array $parsed, string $blockKey, string $label, array &$warnings): array
    {
        $value = $parsed[$blockKey] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('%s[%d] must be a map for BYO %s sync.', $blockKey, $index, $label);

                continue;
            }

            $schedule = is_string($entry['schedule'] ?? null) ? trim($entry['schedule']) : '';
            $command = is_string($entry['command'] ?? null) ? trim($entry['command']) : '';
            if ($schedule === '' || $command === '') {
                if ($schedule !== '' && $command === '') {
                    $warnings[] = sprintf('%s[%d] needs `command` for BYO %s sync.', $blockKey, $index, $label);
                }

                continue;
            }

            $user = is_string($entry['user'] ?? null) ? trim($entry['user']) : null;
            if ($user === '') {
                $user = null;
            }

            $out[] = [
                'schedule' => $schedule,
                'command' => $command,
                'user' => $user,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $parsed
     * @param  array<string, mixed> $warnings
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

    /**
     * @param  array<string, mixed> $parsed
     * @param  array<string, mixed> $warnings
     * @return list<array{name: string, required: bool, description: ?string, default: ?string}>
     */
    private function parseEnvDeclarations(array $parsed, array &$warnings): array
    {
        $value = $parsed['env'] ?? $parsed['env_declarations'] ?? null;
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            $warnings[] = 'env must be a list of declarations for BYO sites.';

            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (is_string($entry)) {
                $name = trim($entry);
                if ($name !== '') {
                    $out[] = ['name' => $name, 'required' => true, 'description' => null, 'default' => null];
                }

                continue;
            }
            if (! is_array($entry)) {
                $warnings[] = sprintf('env[%d] must be a string or map.', $index);

                continue;
            }
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if ($name === '') {
                $warnings[] = sprintf('env[%d].name is required.', $index);

                continue;
            }
            // `default` is a NON-SECRET seed value for the env editor. Coerce
            // scalars to string; secrets should never be committed here (the
            // dashboard owns secret values).
            $default = $entry['default'] ?? null;
            $default = (is_string($default) || is_int($default) || is_float($default) || is_bool($default))
                ? (is_bool($default) ? ($default ? 'true' : 'false') : (string) $default)
                : null;

            $out[] = [
                'name' => $name,
                'required' => (bool) ($entry['required'] ?? true),
                'description' => is_string($entry['description'] ?? null) ? trim($entry['description']) : null,
                'default' => $default,
            ];
        }

        return $out;
    }
}
