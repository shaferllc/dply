<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\RuntimeAwareDeployStepDefaults;

/**
 * Make the build steps agree with what the repository actually is.
 *
 * A site's pipeline is seeded once, from whatever the site's runtime was at
 * creation. Nothing re-seeded it afterwards, so a site created as PHP kept a
 * `composer_install` build step forever — and when a Node repo was connected to
 * it the deploy ran `composer install` and died with "php: command not found"
 * on a server that has no PHP at all.
 *
 * This runs after the post-clone detection has written meta.vm_runtime.detected
 * and swaps the build steps to that language's defaults.
 *
 * Deliberately conservative: it only replaces build steps when EVERY one of
 * them is a recognised default belonging to some other language. A pipeline
 * containing anything hand-written is left alone and reported instead — a
 * deploy pipeline is user-editable, and silently rewriting someone's steps is
 * worse than failing with a clear reason.
 */
final class SiteDeployStepsRuntimeReconciler
{
    public function __construct(
        private readonly RuntimeAwareDeployStepDefaults $defaults,
    ) {}

    /**
     * Reconcile and return a human-readable line for the deploy log, or null
     * when nothing needed changing.
     */
    public function reconcile(Site $site): ?string
    {
        $detected = $site->resolvedRuntimeAppDetection() ?? [];
        $language = strtolower(trim((string) ($detected['language'] ?? '')));

        // The defaults service owns what it can emit, per language, so this
        // covers php / node / python / ruby / go / static without a second copy
        // of that knowledge here.
        $signatures = $this->defaults->knownBuildSignatures();

        if ($language === '' || ! array_key_exists($language, $signatures)) {
            return null;
        }

        $pipeline = $site->deployPipelines()->with('steps')->first();
        if ($pipeline === null) {
            return null;
        }

        $signatureOf = fn (SiteDeployStep $step): string => RuntimeAwareDeployStepDefaults::signature(
            (string) $step->step_type,
            $step->custom_command,
        );

        // Framework names are normalised by defaultsFor() itself, so there is
        // one vocabulary rather than a translation table per call site.
        $framework = strtolower(trim((string) ($detected['framework'] ?? '')));

        $replacements = $this->defaults->defaultsFor(
            $language,
            $framework !== '' ? $framework : null,
            isset($detected['package_manager']) ? (string) $detected['package_manager'] : null,
            isset($detected['migration_tool']) ? (string) $detected['migration_tool'] : null,
        );

        if ($replacements === []) {
            return null;
        }

        // Every known auto-seeded signature, across all languages: anything
        // outside this set was hand-written and must not be discarded.
        $knownSeeded = array_merge(...array_values($signatures));

        $notes = [];

        // Build AND release. Release used to be ignored entirely, so a Node
        // project never got a migration step and a site switched from PHP kept
        // its artisan_migrate steps forever.
        foreach ([SiteDeployStep::PHASE_BUILD, SiteDeployStep::PHASE_RELEASE] as $phase) {
            $current = $pipeline->steps->where('phase', $phase)->values();

            $expectedSteps = array_values(array_filter(
                $replacements,
                fn (array $step): bool => ($step['phase'] ?? null) === $phase,
            ));

            $expected = array_map(
                fn (array $step): string => RuntimeAwareDeployStepDefaults::signature(
                    (string) $step['step_type'],
                    $step['custom_command'] ?? null,
                ),
                $expectedSteps,
            );

            if ($current->map($signatureOf)->all() === $expected) {
                continue;
            }

            // A human edited this phase: say so rather than throwing it away.
            if (! $current->every(fn (SiteDeployStep $step) => in_array($signatureOf($step), $knownSeeded, true))) {
                $notes[] = sprintf(
                    'the %s steps are customised — leaving them untouched',
                    $phase,
                );

                continue;
            }

            $removed = $current->pluck('step_type')->all();

            $pipeline->steps()->where('phase', $phase)->delete();

            foreach ($expectedSteps as $step) {
                $pipeline->steps()->create([
                    'site_id' => $site->id,
                    'step_type' => $step['step_type'],
                    'phase' => $step['phase'],
                    'custom_command' => $step['custom_command'] ?? null,
                    'timeout_seconds' => $step['timeout_seconds'] ?? 600,
                    'sort_order' => $step['sort_order'] ?? 10,
                    'managed_by_manifest' => false,
                ]);
            }

            $notes[] = sprintf(
                'replaced %s steps [%s] with [%s]',
                $phase,
                $removed === [] ? 'none' : implode(', ', $removed),
                implode(', ', array_column($expectedSteps, 'step_type')) ?: 'none',
            );
        }

        if ($notes === []) {
            return null;
        }

        return sprintf(
            '[dply] Detected a %s project (%s) — %s.',
            $language,
            $framework !== '' ? $framework : 'no framework',
            implode('; ', $notes),
        );
    }
}
