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

        $buildSteps = $pipeline->steps
            ->where('phase', SiteDeployStep::PHASE_BUILD)
            ->values();

        if ($buildSteps->isEmpty()) {
            return null;
        }

        $signatureOf = fn (SiteDeployStep $s): string => RuntimeAwareDeployStepDefaults::signature(
            (string) $s->step_type,
            $s->custom_command,
        );

        // Already correct for the detected language: leave it alone.
        if ($buildSteps->every(fn (SiteDeployStep $s) => in_array($signatureOf($s), $signatures[$language], true))) {
            return null;
        }

        $knownForeign = array_merge(...array_values(array_diff_key($signatures, [$language => true])));

        // Anything unrecognised means a human edited this pipeline. Say so
        // rather than throwing their work away. Signature (type + command)
        // matters here: python/ruby/go/static all emit TYPE_CUSTOM, so the type
        // alone cannot tell an auto-seeded `go build` from a hand-written one.
        if (! $buildSteps->every(fn (SiteDeployStep $s) => in_array($signatureOf($s), $knownForeign, true))) {
            return sprintf(
                '[dply] Detected a %s project, but the build steps are customised — leaving them untouched. '
                .'Update them on the site\'s Pipeline tab if the deploy fails.',
                $language,
            );
        }

        // Framework names are normalised by defaultsFor() itself, so there is
        // one vocabulary rather than a translation table per call site.
        $framework = strtolower(trim((string) ($detected['framework'] ?? '')));

        $replacements = $this->defaults->defaultsFor($language, $framework !== '' ? $framework : null);

        if ($replacements === []) {
            return null;
        }

        $removed = $buildSteps->pluck('step_type')->all();

        $pipeline->steps()
            ->where('phase', SiteDeployStep::PHASE_BUILD)
            ->delete();

        foreach ($replacements as $step) {
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

        return sprintf(
            '[dply] Detected a %s project (%s) — replaced auto-seeded build steps [%s] with [%s].',
            $language,
            $framework !== '' ? $framework : 'no framework',
            implode(', ', $removed),
            implode(', ', array_column($replacements, 'step_type')),
        );
    }
}
