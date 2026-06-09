<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\SiteDeployHook;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use App\Services\Sites\SiteDeployPipelineCommands;
use Illuminate\Support\Collection;

/**
 * Human-readable bash representation of a deploy pipeline (reference / CI paste).
 */
final class DeployPipelineScriptExporter
{
    private const RELEASE_DIR = '${DPLY_RELEASE_DIR:-/var/www/site/current}';

    public function toFullBash(SiteDeployPipeline $pipeline): string
    {
        $pipeline->loadMissing(['steps', 'hooks']);
        $lines = [
            '#!/usr/bin/env bash',
            '# Dply deploy pipeline export — reference only.',
            '# Dply still runs the visual pipeline on deploy (clone, activate, webhooks, notifications).',
            'set -euo pipefail',
            '',
            'RELEASE_DIR='.self::RELEASE_DIR,
            'cd "$RELEASE_DIR"',
            '',
        ];

        $this->appendSection($lines, 'Before clone', $this->shellHooks($pipeline, SiteDeployHook::ANCHOR_BEFORE_CLONE));
        $lines[] = '# --- Clone (managed by Dply) ---';
        if (trim((string) $pipeline->clone_script) !== '') {
            $lines[] = '# Custom clone script on this pipeline:';
            $lines = array_merge($lines, $this->indentScript((string) $pipeline->clone_script));
        }
        $lines[] = '';

        $this->appendSection($lines, 'After clone', $this->shellHooks($pipeline, SiteDeployHook::ANCHOR_AFTER_CLONE));

        $buildSteps = $pipeline->steps->where('phase', SiteDeployStep::PHASE_BUILD)->sortBy('sort_order');
        $this->appendStepsSection($lines, 'Build', $buildSteps, $pipeline);

        $this->appendSection($lines, 'Before activate', $this->shellHooks($pipeline, SiteDeployHook::ANCHOR_BEFORE_ACTIVATE));
        $lines[] = '# --- Activate / symlink (managed by Dply) ---';
        if (trim((string) $pipeline->activate_script) !== '') {
            $lines[] = '# Custom activate script on this pipeline:';
            $lines = array_merge($lines, $this->indentScript((string) $pipeline->activate_script));
        }
        $lines[] = '';

        $releaseSteps = $pipeline->steps->where('phase', SiteDeployStep::PHASE_RELEASE)->sortBy('sort_order');
        $this->appendStepsSection($lines, 'Release', $releaseSteps, $pipeline);

        $this->appendSection($lines, 'After activate', $this->shellHooks($pipeline, SiteDeployHook::ANCHOR_AFTER_ACTIVATE));

        $this->appendNonShellHookComments($lines, $pipeline);

        return implode("\n", $lines)."\n";
    }

    public function toCommandsOnly(SiteDeployPipeline $pipeline): string
    {
        $pipeline->loadMissing(['steps']);
        $lines = [
            '# Dply pipeline — build and release commands only',
            '',
        ];

        foreach ([SiteDeployStep::PHASE_BUILD => 'Build', SiteDeployStep::PHASE_RELEASE => 'Release'] as $phase => $label) {
            $steps = $pipeline->steps->where('phase', $phase)->sortBy('sort_order');
            if ($steps->isEmpty()) {
                continue;
            }
            $lines[] = "# {$label}";
            foreach ($steps as $step) {
                $cmd = $this->commandForStep($step);
                if ($cmd !== null && $cmd !== '') {
                    $lines[] = $cmd;
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $scriptLines
     */
    private function appendSection(array &$lines, string $title, array $scriptLines): void
    {
        if ($scriptLines === []) {
            return;
        }
        $lines[] = "# --- {$title} ---";
        $lines = array_merge($lines, $scriptLines);
        $lines[] = '';
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $steps
     * @param  list<string>  $lines
     */
    private function appendStepsSection(
        array &$lines,
        string $title,
        $steps,
        SiteDeployPipeline $pipeline,
    ): void {
        if ($steps->isEmpty()) {
            return;
        }
        $lines[] = "# --- {$title} ---";
        foreach ($steps as $step) {
            $cmd = $this->commandForStep($step);
            if ($cmd !== null && $cmd !== '') {
                $lines[] = $cmd;
            }
            $after = $this->shellHooksAfterStep($pipeline, $step);
            if ($after !== []) {
                $lines[] = '# After step: '.$step->pillLabel();
                $lines = array_merge($lines, $after);
            }
        }
        $lines[] = '';
    }

    private function commandForStep(SiteDeployStep $step): ?string
    {
        $custom = trim((string) ($step->custom_command ?? ''));

        return SiteDeployPipelineCommands::fragmentFor($step->step_type, $custom);
    }

    /**
     * @return list<string>
     */
    private function shellHooks(SiteDeployPipeline $pipeline, string $anchor): array
    {
        return $pipeline->hooks
            ->where('hook_kind', SiteDeployHook::KIND_SHELL)
            ->where('anchor', $anchor)
            ->whereNull('anchor_step_id')
            ->sortBy('sort_order')
            ->flatMap(fn (SiteDeployHook $hook) => $this->indentScript((string) $hook->script))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function shellHooksAfterStep(SiteDeployPipeline $pipeline, SiteDeployStep $step): array
    {
        return $pipeline->hooks
            ->where('hook_kind', SiteDeployHook::KIND_SHELL)
            ->where('anchor', SiteDeployHook::ANCHOR_AFTER_STEP)
            ->where('anchor_step_id', $step->id)
            ->sortBy('sort_order')
            ->flatMap(fn (SiteDeployHook $hook) => $this->indentScript((string) $hook->script))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendNonShellHookComments(array &$lines, SiteDeployPipeline $pipeline): void
    {
        $other = $pipeline->hooks->whereIn('hook_kind', [
            SiteDeployHook::KIND_WEBHOOK,
            SiteDeployHook::KIND_NOTIFICATION,
        ]);

        if ($other->isEmpty()) {
            return;
        }

        $lines[] = '# --- Hooks not exported as bash (run by Dply on deploy) ---';
        foreach ($other as $hook) {
            $lines[] = '# '.$hook->pillLabel().' · '.$hook->anchor.' · '.$hook->hook_kind;
        }
        $lines[] = '';
    }

    /**
     * @return list<string>
     */
    private function indentScript(string $script): array
    {
        $trimmed = rtrim($script);
        if ($trimmed === '') {
            return [];
        }

        return explode("\n", $trimmed);
    }
}
