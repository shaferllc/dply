<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use App\Services\Deploy\SiteDeployPipelineManager;

/**
 * The "simple text pipeline": each deploy phase (build / release / restart) is a
 * single shell script the operator edits as plain text, instead of the visual
 * step builder. Backed by exactly ONE {@see SiteDeployStep} of TYPE_CUSTOM per
 * phase, so the existing deploy runner executes it unchanged.
 *
 * - {@see render()}   structured steps → text per phase (editor initial load)
 * - {@see preset()}   a framework preset → text per phase (built from the same
 *                     RuntimeAwareDeployStepDefaults the visual pipeline uses)
 * - {@see apply()}    text per phase → one custom step per phase (save)
 */
class DeployScriptComposer
{
    /** Phases the text editor exposes, in deploy order. */
    public const PHASES = [
        SiteDeployStep::PHASE_BUILD,
        SiteDeployStep::PHASE_RELEASE,
        SiteDeployStep::PHASE_RESTART,
    ];

    /**
     * Render a site's freeform (TYPE_CUSTOM) deploy commands to one editable
     * script per phase. Only the *trailing* custom block is the text editor's:
     * typed steps and any custom steps pinned before them (e.g. a safety
     * preset's pre-migrate DB snapshot inserted ahead of `migrate`) are shown
     * read-only via {@see lockedSteps()}, so the textarea never absorbs — and
     * a later save can never relocate — a command that must run before a
     * builder step. See {@see typedCutoff()} for the boundary.
     *
     * @return array<string, string>  phase => script text
     */
    public function render(Site $site): array
    {
        $site->loadMissing('deploySteps');

        $out = [];
        foreach (self::PHASES as $phase) {
            $cutoff = $this->typedCutoff($site, $phase);
            $lines = $site->deploySteps
                ->where('phase', $phase)
                ->where('step_type', SiteDeployStep::TYPE_CUSTOM)
                ->filter(fn (SiteDeployStep $s) => $cutoff === null || (int) $s->sort_order > $cutoff)
                ->sortBy('sort_order')
                ->map(fn (SiteDeployStep $s) => $s->commandFor())
                ->filter(fn ($c) => is_string($c) && trim($c) !== '')
                ->values()
                ->all();
            $out[$phase] = implode("\n", $lines);
        }

        return $out;
    }

    /**
     * Steps per phase the text editor does NOT own — shown as locked read-only
     * rows so the run order is honest even though the textarea only edits the
     * trailing freeform portion. This is every typed (non-custom) step PLUS any
     * custom step pinned at or before the last typed step (preset/visual-builder
     * authored, e.g. a pre-migrate backup). Returned in true execution order.
     * Normally empty for purely text-authored pipelines.
     *
     * @return array<string, list<SiteDeployStep>>  phase => locked steps
     */
    public function lockedSteps(Site $site): array
    {
        $site->loadMissing('deploySteps');

        $out = [];
        foreach (self::PHASES as $phase) {
            $cutoff = $this->typedCutoff($site, $phase);
            $out[$phase] = $site->deploySteps
                ->where('phase', $phase)
                ->filter(fn (SiteDeployStep $s) => $s->step_type !== SiteDeployStep::TYPE_CUSTOM
                    || ($cutoff !== null && (int) $s->sort_order <= $cutoff))
                ->sortBy('sort_order')
                ->values()
                ->all();
        }

        return $out;
    }

    /**
     * The sort_order of the last typed (non-custom) step in a phase, or null
     * when the phase has none. Custom steps at or before this boundary are
     * "pinned" (run among the typed steps and are locked); custom steps after
     * it form the trailing freeform block the text editor owns. With no typed
     * steps every custom step is trailing — the original text-pipeline shape.
     */
    private function typedCutoff(Site $site, string $phase): ?int
    {
        $orders = $site->deploySteps
            ->where('phase', $phase)
            ->where('step_type', '!=', SiteDeployStep::TYPE_CUSTOM)
            ->pluck('sort_order');

        return $orders->isEmpty() ? null : (int) $orders->max();
    }

    /**
     * A built-in framework preset rendered to one script per phase. Generated
     * from RuntimeAwareDeployStepDefaults so presets never drift from what the
     * structured pipeline would have run.
     *
     * @return array<string, string>  phase => script text
     */
    public function preset(string $runtime, ?string $framework = null): array
    {
        $defaults = app(RuntimeAwareDeployStepDefaults::class)->defaultsFor($runtime, $framework);

        $grouped = array_fill_keys(self::PHASES, []);
        foreach ($defaults as $def) {
            $phase = $def['phase'] ?? SiteDeployStep::PHASE_BUILD;
            if (! in_array($phase, self::PHASES, true)) {
                continue;
            }
            $cmd = (new SiteDeployStep([
                'step_type' => $def['step_type'],
                'custom_command' => $def['custom_command'] ?? null,
                'phase' => $phase,
            ]))->commandFor();
            if (is_string($cmd) && trim($cmd) !== '') {
                $grouped[$phase][] = $cmd;
            }
        }

        return array_map(static fn (array $lines): string => implode("\n", $lines), $grouped);
    }

    /**
     * Persist the text blocks as exactly one TYPE_CUSTOM step per phase, WITHOUT
     * destroying structure: only the *trailing* freeform custom step(s) — the
     * ones after the last typed step — are replaced. Typed steps, hooks, and
     * custom steps pinned before a typed step (e.g. a pre-migrate DB snapshot a
     * safety preset inserts ahead of `migrate`) are left exactly where they are,
     * so saving the text editor can never yank such a command past its
     * migration. The blob sorts after every surviving step. An empty block
     * clears only the phase's trailing custom step.
     *
     * @param  array<string, string>  $scripts  phase => script text
     */
    public function apply(Site $site, array $scripts): void
    {
        $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

        foreach (self::PHASES as $phase) {
            // Replace ONLY the trailing freeform portion (custom steps after the
            // last typed step); preserve typed steps, hooks, and pinned customs.
            $typedMax = $pipeline->steps()
                ->where('phase', $phase)
                ->where('step_type', '!=', SiteDeployStep::TYPE_CUSTOM)
                ->max('sort_order');

            $trailing = $pipeline->steps()
                ->where('phase', $phase)
                ->where('step_type', SiteDeployStep::TYPE_CUSTOM);
            if ($typedMax !== null) {
                $trailing->where('sort_order', '>', $typedMax);
            }
            $trailing->delete();

            $text = trim((string) ($scripts[$phase] ?? ''));
            if ($text === '') {
                continue;
            }

            // Run the custom blob after every surviving step in this phase.
            $maxOrder = (int) $pipeline->steps()->where('phase', $phase)->max('sort_order');

            $pipeline->steps()->create([
                'site_id' => $site->id,
                'sort_order' => $maxOrder + 1,
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'phase' => $phase,
                'custom_command' => $text,
                'timeout_seconds' => 1800,
            ]);
        }

        $site->load('deploySteps');
    }
}
