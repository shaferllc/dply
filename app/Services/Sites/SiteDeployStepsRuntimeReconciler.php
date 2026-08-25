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
    /**
     * Build-phase step types that a language's defaults can produce, used to
     * recognise "this pipeline was auto-seeded for language X".
     *
     * @var array<string, list<string>>
     */
    private const LANGUAGE_BUILD_STEPS = [
        'php' => [
            SiteDeployStep::TYPE_COMPOSER_INSTALL,
        ],
        'node' => [
            SiteDeployStep::TYPE_NPM_CI,
            SiteDeployStep::TYPE_NPM_INSTALL,
            SiteDeployStep::TYPE_NPM_RUN,
            SiteDeployStep::TYPE_YARN_INSTALL,
            SiteDeployStep::TYPE_PNPM_INSTALL,
            SiteDeployStep::TYPE_BUN_INSTALL,
        ],
    ];

    /**
     * The two detectors disagree on Node framework names — RepositoryRuntimeDetector
     * emits `nextjs`, RuntimeAwareDeployStepDefaults expects `next` — so the
     * build step for a Next.js repo silently went missing. Normalise here.
     *
     * @var array<string, string>
     */
    private const FRAMEWORK_ALIASES = [
        'nextjs' => 'next',
        'nuxtjs' => 'nuxt',
        'node_generic' => '',
    ];

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

        if ($language === '' || ! array_key_exists($language, self::LANGUAGE_BUILD_STEPS)) {
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

        $ownTypes = self::LANGUAGE_BUILD_STEPS[$language];

        // Already correct for the detected language: leave it alone.
        if ($buildSteps->every(fn (SiteDeployStep $s) => in_array($s->step_type, $ownTypes, true))) {
            return null;
        }

        $knownForeign = array_merge(...array_values(array_diff_key(
            self::LANGUAGE_BUILD_STEPS,
            [$language => true],
        )));

        // Anything unrecognised means a human edited this pipeline. Say so
        // rather than throwing their work away.
        if (! $buildSteps->every(fn (SiteDeployStep $s) => in_array($s->step_type, $knownForeign, true))) {
            return sprintf(
                '[dply] Detected a %s project, but the build steps are customised — leaving them untouched. '
                .'Update them on the site\'s Pipeline tab if the deploy fails.',
                $language,
            );
        }

        $framework = strtolower(trim((string) ($detected['framework'] ?? '')));
        $framework = self::FRAMEWORK_ALIASES[$framework] ?? $framework;

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
