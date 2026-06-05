<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use App\Services\Sites\SiteDeployPipelineManager;

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
     * Render a site's current deploy steps to one shell script per phase.
     *
     * @return array<string, string>  phase => script text
     */
    public function render(Site $site): array
    {
        $site->loadMissing('deploySteps');

        $out = [];
        foreach (self::PHASES as $phase) {
            $lines = $site->deploySteps
                ->where('phase', $phase)
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
     * Persist the text blocks as exactly one TYPE_CUSTOM step per phase. An
     * empty block leaves that phase with no step. Collapses any existing
     * structured steps in these phases — lossy by design (the whole point of the
     * simple text mode).
     *
     * @param  array<string, string>  $scripts  phase => script text
     */
    public function apply(Site $site, array $scripts): void
    {
        $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

        foreach (self::PHASES as $phase) {
            $pipeline->steps()->where('phase', $phase)->delete();

            $text = trim((string) ($scripts[$phase] ?? ''));
            if ($text === '') {
                continue;
            }

            $pipeline->steps()->create([
                'site_id' => $site->id,
                'sort_order' => 0,
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'phase' => $phase,
                'custom_command' => $text,
                'timeout_seconds' => 1800,
            ]);
        }

        $site->load('deploySteps');
    }
}
