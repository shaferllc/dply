<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Sites\SiteDeployPipelineCommands;

/**
 * Filters and resolves deploy pipeline palette entries for a site.
 */
final class DeployPipelinePalette
{
    /**
     * @return list<array{type: string, label: string, icon: string, phase: string, custom_command?: string, requires?: string}>
     */
    public static function allPaletteEntries(): array
    {
        return array_values(config('site_deploy_pipeline.palette', []));
    }

    /**
     * Full step catalog grouped for Reference / browse UI (includes runtime-gated entries).
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     description: string,
     *     entries: list<array{
     *         type: string,
     *         label: string,
     *         icon: string,
     *         phase: string,
     *         custom_command?: string,
     *         requires?: string,
     *         requires_label: ?string,
     *         visible: bool,
     *         command_preview: ?string,
     *         catalog_key: string
     *     }>
     * }>
     */
    public static function stepCatalogFor(Site $site): array
    {
        $grouped = [];
        foreach (self::allPaletteEntries() as $index => $entry) {
            $groupId = self::catalogGroupId($entry);
            $type = (string) ($entry['type'] ?? SiteDeployStep::TYPE_CUSTOM);
            $custom = isset($entry['custom_command']) ? (string) $entry['custom_command'] : null;

            $grouped[$groupId][] = [
                'type' => $type,
                'label' => (string) ($entry['label'] ?? $type),
                'icon' => (string) ($entry['icon'] ?? 'heroicon-o-plus'),
                'phase' => (string) ($entry['phase'] ?? SiteDeployStep::PHASE_BUILD),
                'custom_command' => $custom,
                'requires' => $entry['requires'] ?? null,
                'requires_label' => self::requiresLabel($entry['requires'] ?? null),
                'visible' => self::entryVisible($site, $entry),
                'command_preview' => self::commandPreview($type, $custom),
                'catalog_key' => $groupId.'-'.$index,
            ];
        }

        $groups = config('site_deploy_pipeline.catalog_groups', []);
        $ordered = collect($grouped)
            ->map(function (array $entries, string $id) use ($groups): array {
                $meta = $groups[$id] ?? [];

                return [
                    'id' => $id,
                    'label' => (string) ($meta['label'] ?? str($id)->headline()),
                    'description' => (string) ($meta['description'] ?? ''),
                    'order' => (int) ($meta['order'] ?? 999),
                    'entries' => $entries,
                ];
            })
            ->sortBy('order')
            ->values()
            ->all();

        return $ordered;
    }

    /**
     * Every built-in step type (for the type reference table).
     *
     * @return list<array{
     *     type: string,
     *     label: string,
     *     default_phase: string,
     *     command_preview: ?string,
     *     needs_custom_command: bool
     * }>
     */
    public static function stepTypeReference(): array
    {
        $rows = [];
        foreach (SiteDeployStep::typeLabels() as $type => $label) {
            $preview = self::commandPreview($type, $type === SiteDeployStep::TYPE_NPM_RUN ? 'build' : null);
            $rows[] = [
                'type' => $type,
                'label' => $label,
                'default_phase' => SiteDeployStep::defaultPhaseFor($type),
                'command_preview' => $preview,
                'needs_custom_command' => SiteDeployStep::needsCustomCommand($type),
            ];
        }

        return $rows;
    }

    /**
     * Hook types + all presets (including runtime-gated).
     *
     * @return array{
     *     types: list<array{kind: string, label: string, icon: string}>,
     *     presets: list<array{
     *         kind: string,
     *         label: string,
     *         icon: string,
     *         anchor?: string,
     *         script?: string,
     *         requires?: string,
     *         requires_label: ?string,
     *         visible: bool
     *     }>
     * }
     */
    public static function hookCatalogFor(Site $site): array
    {
        $presets = collect(config('site_deploy_pipeline.hook_presets', []))
            ->map(fn (array $entry): array => [
                'kind' => (string) ($entry['kind'] ?? 'shell'),
                'label' => (string) ($entry['label'] ?? ''),
                'icon' => (string) ($entry['icon'] ?? 'heroicon-o-bolt'),
                'anchor' => $entry['anchor'] ?? null,
                'script' => $entry['script'] ?? null,
                'requires' => $entry['requires'] ?? null,
                'requires_label' => self::requiresLabel($entry['requires'] ?? null),
                'visible' => self::entryVisible($site, $entry),
            ])
            ->values()
            ->all();

        return [
            'types' => array_values(config('site_deploy_pipeline.hook_palette', [])),
            'presets' => $presets,
        ];
    }

    public static function commandPreview(string $stepType, ?string $customCommand = null): ?string
    {
        $custom = trim((string) $customCommand);

        return SiteDeployPipelineCommands::fragmentFor($stepType, $custom !== '' ? $custom : '');
    }

    public static function requiresLabel(?string $requires): ?string
    {
        if ($requires === null || $requires === '') {
            return null;
        }

        return match ($requires) {
            'laravel' => __('Laravel'),
            'rails' => __('Rails'),
            'node' => __('Node'),
            'php' => __('PHP'),
            'ruby' => __('Ruby'),
            'static' => __('Static'),
            default => str($requires)->headline()->toString(),
        };
    }

    /**
     * @param  array{requires?: string, phase?: string}  $entry
     */
    private static function catalogGroupId(array $entry): string
    {
        $requires = $entry['requires'] ?? null;
        $phase = (string) ($entry['phase'] ?? SiteDeployStep::PHASE_BUILD);

        if ($requires === null || $requires === '') {
            return $phase === SiteDeployStep::PHASE_RELEASE ? 'generic_release' : 'generic';
        }

        return strtolower((string) $requires).'_'.$phase;
    }

    /**
     * @return list<array{type: string, label: string, icon: string, phase: string, custom_command?: string, requires?: string}>
     */
    public static function stepsFor(Site $site): array
    {
        return collect(config('site_deploy_pipeline.palette', []))
            ->filter(fn (array $entry): bool => self::entryVisible($site, $entry))
            ->values()
            ->all();
    }

    /**
     * @return list<array{kind: string, label: string, icon: string, anchor?: string, script?: string}>
     */
    public static function hookPresetsFor(Site $site): array
    {
        return collect(config('site_deploy_pipeline.hook_presets', []))
            ->filter(fn (array $entry): bool => self::entryVisible($site, $entry))
            ->values()
            ->all();
    }

    /**
     * @param  array{requires?: string}  $entry
     */
    public static function entryVisible(Site $site, array $entry): bool
    {
        $requires = $entry['requires'] ?? null;
        if ($requires === null || $requires === '') {
            return true;
        }

        return match ($requires) {
            'laravel' => $site->isLaravelFrameworkDetected(),
            'rails' => $site->isRailsFrameworkDetected(),
            'node' => $site->runtimeKey() === 'node',
            'php' => $site->runtimeKey() === 'php',
            'ruby' => $site->runtimeKey() === 'ruby',
            'static' => $site->runtimeKey() === 'static',
            default => true,
        };
    }
}
