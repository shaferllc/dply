<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\SiteDeployHook;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use Illuminate\Support\Collection;

/**
 * Ordered pipeline timeline for the Build steps UI (steps + hooks).
 *
 * Execution order: before clone → clone → after clone → build steps →
 * before activate → activate (swap) → release steps (e.g. migrations) → after activate.
 */
final class DeployPipelineTimeline
{
    /**
     * @return list<array{type: string, key: string, step?: SiteDeployStep, hook?: SiteDeployHook}>
     */
    public static function items(SiteDeployPipeline $pipeline): array
    {
        $steps = $pipeline->relationLoaded('steps')
            ? $pipeline->steps
            : $pipeline->steps()->orderBy('sort_order')->get();
        $hooks = $pipeline->relationLoaded('hooks')
            ? $pipeline->hooks
            : $pipeline->hooks()->orderBy('sort_order')->get();

        $buildSteps = $steps->where('phase', SiteDeployStep::PHASE_BUILD)->values();
        $releaseSteps = $steps
            ->where('phase', SiteDeployStep::PHASE_RELEASE)
            ->values();

        $items = [];

        foreach (self::hooksForAnchor($hooks, SiteDeployHook::ANCHOR_BEFORE_CLONE) as $hook) {
            $items[] = self::hookItem($hook);
        }

        $items[] = ['type' => 'anchor', 'key' => 'clone'];

        foreach (self::hooksForAnchor($hooks, SiteDeployHook::ANCHOR_AFTER_CLONE) as $hook) {
            $items[] = self::hookItem($hook);
        }

        foreach ($buildSteps as $step) {
            $items[] = ['type' => 'step', 'key' => 'step-'.$step->id, 'step' => $step];
            foreach (self::hooksAfterStep($hooks, $step) as $hook) {
                $items[] = self::hookItem($hook);
            }
        }

        foreach (self::hooksForAnchor($hooks, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE) as $hook) {
            $items[] = self::hookItem($hook);
        }

        $items[] = ['type' => 'anchor', 'key' => 'activate'];

        foreach ($releaseSteps as $step) {
            $items[] = ['type' => 'step', 'key' => 'step-'.$step->id, 'step' => $step];
            foreach (self::hooksAfterStep($hooks, $step) as $hook) {
                $items[] = self::hookItem($hook);
            }
        }

        foreach (self::hooksForAnchor($hooks, SiteDeployHook::ANCHOR_AFTER_ACTIVATE) as $hook) {
            $items[] = self::hookItem($hook);
        }

        return $items;
    }

    /**
     * Split timeline for drag-and-drop UI.
     *
     * @return array{
     *     prefix: list<array{type: string, key: string, step?: SiteDeployStep, hook?: SiteDeployHook}>,
     *     buildBlocks: list<array{step: SiteDeployStep, hooks: list<SiteDeployHook>}>,
     *     mid: list<array{type: string, key: string, hook?: SiteDeployHook}>,
     *     releaseBlocks: list<array{step: SiteDeployStep, hooks: list<SiteDeployHook>}>,
     *     suffix: list<array{type: string, key: string, hook?: SiteDeployHook}>
     * }
     */
    /**
     * @param  list<array{type: string, key: string, step?: SiteDeployStep, hook?: SiteDeployHook}>|null  $items
     */
    public static function splitForUi(SiteDeployPipeline $pipeline, ?array $items = null): array
    {
        $prefix = [];
        $buildBlocks = [];
        $mid = [];
        $releaseBlocks = [];
        $suffix = [];
        $current = null;
        $zone = 'prefix';

        foreach ($items ?? self::items($pipeline) as $item) {
            if ($item['type'] === 'anchor' && $item['key'] === 'activate') {
                if ($current !== null) {
                    $buildBlocks[] = $current;
                    $current = null;
                }
                $mid[] = $item;
                $zone = 'release';

                continue;
            }

            if ($item['type'] === 'step') {
                if ($current !== null) {
                    self::pushStepBlock($buildBlocks, $releaseBlocks, $zone, $current);
                }
                $current = ['step' => $item['step'], 'hooks' => []];

                continue;
            }

            if ($item['type'] === 'hook') {
                $hook = $item['hook'];
                if ($hook->anchor === SiteDeployHook::ANCHOR_AFTER_STEP && $current !== null) {
                    $current['hooks'][] = $hook;

                    continue;
                }
                if ($current !== null) {
                    self::pushStepBlock($buildBlocks, $releaseBlocks, $zone, $current);
                    $current = null;
                }
                match ($hook->anchor) {
                    SiteDeployHook::ANCHOR_BEFORE_ACTIVATE => $mid[] = $item,
                    SiteDeployHook::ANCHOR_AFTER_ACTIVATE => $suffix[] = $item,
                    default => $prefix[] = $item,
                };

                continue;
            }

            if ($item['type'] === 'anchor') {
                $prefix[] = $item;
                if ($item['key'] === 'clone') {
                    $zone = 'build';
                }
            }
        }

        if ($current !== null) {
            self::pushStepBlock($buildBlocks, $releaseBlocks, $zone, $current);
        }

        return [
            'prefix' => $prefix,
            'buildBlocks' => $buildBlocks,
            'mid' => $mid,
            'releaseBlocks' => $releaseBlocks,
            'suffix' => $suffix,
        ];
    }

    /**
     * @param  Collection<int, SiteDeployHook>  $hooks
     * @return Collection<int, SiteDeployHook>
     */
    private static function hooksForAnchor(Collection $hooks, string $anchor): Collection
    {
        return $hooks
            ->where('anchor', $anchor)
            ->whereNull('anchor_step_id')
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @param  Collection<int, SiteDeployHook>  $hooks
     * @return Collection<int, SiteDeployHook>
     */
    private static function hooksAfterStep(Collection $hooks, SiteDeployStep $step): Collection
    {
        return $hooks
            ->where('anchor', SiteDeployHook::ANCHOR_AFTER_STEP)
            ->where('anchor_step_id', $step->id)
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @return array{type: string, key: string, hook: SiteDeployHook}
     */
    private static function hookItem(SiteDeployHook $hook): array
    {
        return ['type' => 'hook', 'key' => 'hook-'.$hook->id, 'hook' => $hook];
    }

    /**
     * @param  list<array{step: SiteDeployStep, hooks: list<SiteDeployHook>}>  $buildBlocks
     * @param  list<array{step: SiteDeployStep, hooks: list<SiteDeployHook>}>  $releaseBlocks
     * @param  array{step: SiteDeployStep, hooks: list<SiteDeployHook>}  $block
     */
    private static function pushStepBlock(array &$buildBlocks, array &$releaseBlocks, string $zone, array $block): void
    {
        if ($zone === 'release') {
            $releaseBlocks[] = $block;
        } else {
            $buildBlocks[] = $block;
        }
    }
}
