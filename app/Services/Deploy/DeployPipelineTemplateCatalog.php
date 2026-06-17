<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;

/**
 * Built-in Dply pipeline templates (config) plus runtime-aware defaults.
 */
final class DeployPipelineTemplateCatalog
{
    public function __construct(
        private readonly RuntimeAwareDeployStepDefaults $runtimeDefaults,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     runtime: ?string,
     *     framework: ?string,
     *     steps: list<array{step_type: string, phase: string, custom_command?: string, timeout_seconds: int}>,
     * }>
     */
    /** @return array<string, mixed> */
    /**
     * @return array<string, mixed>
     */
    public function templatesForSite(Site $site): array
    {
        $templates = [];
        $seen = [];

        foreach ((array) config('site_deploy_pipeline_templates.templates', []) as $key => $meta) {
            if (! is_string($key) || ! is_array($meta)) {
                continue;
            }
            $steps = $this->stepsFromTemplateMeta($meta);
            if ($steps === []) {
                continue;
            }
            $templates[] = $this->formatTemplate($key, $meta, $steps);
            $seen[$key] = true;
        }

        $runtime = $site->runtime;
        $framework = strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? '')) ?: null;
        if ($runtime) {
            $runtimeKey = 'runtime-'.$runtime.($framework ? '-'.$framework : '');
            if (! isset($seen[$runtimeKey])) {
                $steps = $this->runtimeDefaults->defaultsFor($runtime, $framework);
                if ($steps !== []) {
                    $templates[] = [
                        'key' => $runtimeKey,
                        'label' => __('Runtime defaults (:runtime)', ['runtime' => $runtime]),
                        'description' => __('Suggested steps for this site’s detected runtime.'),
                        'runtime' => $runtime,
                        'framework' => $framework,
                        'steps' => $this->stripSortOrder($steps),
                    ];
                }
            }
        }

        return $templates;
    }

    /**
     * @return list<array<string, array|string|null>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, int|string>>
     */
    public function stepsForTemplateKey(string $key): array
    {
        $meta = config("site_deploy_pipeline_templates.templates.{$key}");
        if (! is_array($meta)) {
            if (str_starts_with($key, 'runtime-')) {
                $parts = explode('-', substr($key, 8), 2);
                $runtime = $parts[0] ?? null;
                $framework = $parts[1] ?? null;

                return $this->stripSortOrder($this->runtimeDefaults->defaultsFor($runtime, $framework));
            }

            return [];
        }

        return $this->stepsFromTemplateMeta($meta);
    }

    /**
     * @param  array<string, mixed> $meta
     * @return list<array<string, int|string>>
     */
    private function stepsFromTemplateMeta(array $meta): array
    {
        if (isset($meta['runtime']) && is_string($meta['runtime'])) {
            $framework = isset($meta['framework']) && is_string($meta['framework']) ? $meta['framework'] : null;

            return $this->stripSortOrder($this->runtimeDefaults->defaultsFor($meta['runtime'], $framework));
        }

        $steps = $meta['steps'] ?? [];
        if (! is_array($steps)) {
            return [];
        }

        $normalized = [];
        foreach ($steps as $step) {
            if (! is_array($step) || ! isset($step['step_type'])) {
                continue;
            }
            $normalized[] = [
                'step_type' => (string) $step['step_type'],
                'phase' => (string) ($step['phase'] ?? 'build'),
                'custom_command' => isset($step['custom_command']) ? (string) $step['custom_command'] : null,
                'timeout_seconds' => (int) ($step['timeout_seconds'] ?? 900),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed> $meta
     * @param  array<string, mixed> $steps
     * @return array{key: string, label: string, description: string, runtime: string|null, framework: string|null, steps: array<string, mixed>}
     */
    private function formatTemplate(string $key, array $meta, array $steps): array
    {
        return [
            'key' => $key,
            'label' => (string) ($meta['label'] ?? $key),
            'description' => (string) ($meta['description'] ?? ''),
            'runtime' => isset($meta['runtime']) ? (string) $meta['runtime'] : null,
            'framework' => isset($meta['framework']) ? (string) $meta['framework'] : null,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, mixed> $steps
     * @return list<array{step_type: string, phase: string, custom_command?: string, timeout_seconds: int}>
     */
    private function stripSortOrder(array $steps): array
    {
        return array_map(static function (array $step): array {
            return [
                'step_type' => (string) $step['step_type'],
                'phase' => (string) ($step['phase'] ?? 'build'),
                'custom_command' => $step['custom_command'] ?? null,
                'timeout_seconds' => (int) ($step['timeout_seconds'] ?? 900),
            ];
        }, $steps);
    }
}
