<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use Illuminate\Support\Collection;

/**
 * Heuristic checks for deploy pipeline configuration (ordering, phases, hooks).
 */
final class DeployPipelineAdvisor
{
    /** @var list<string> */
    private const NODE_INSTALL_TYPES = [
        SiteDeployStep::TYPE_NPM_CI,
        SiteDeployStep::TYPE_NPM_INSTALL,
        SiteDeployStep::TYPE_YARN_INSTALL,
        SiteDeployStep::TYPE_PNPM_INSTALL,
        SiteDeployStep::TYPE_BUN_INSTALL,
    ];

    /** @var list<string> */
    private const BUILD_ONLY_TYPES = [
        SiteDeployStep::TYPE_COMPOSER_INSTALL,
        SiteDeployStep::TYPE_NPM_CI,
        SiteDeployStep::TYPE_NPM_INSTALL,
        SiteDeployStep::TYPE_YARN_INSTALL,
        SiteDeployStep::TYPE_PNPM_INSTALL,
        SiteDeployStep::TYPE_BUN_INSTALL,
        SiteDeployStep::TYPE_NPM_RUN,
        SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE,
        SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE,
        SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE,
        SiteDeployStep::TYPE_ARTISAN_EVENT_CACHE,
        SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL,
        SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL,
    ];

    /** @var list<string> */
    private const CACHE_WARM_TYPES = [
        SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE,
        SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE,
        SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE,
        SiteDeployStep::TYPE_ARTISAN_EVENT_CACHE,
        SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
    ];

    /** @var list<string> */
    private const SCAFFOLDING_TYPES = [
        SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL,
        SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL,
    ];

    /**
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     checks: list<array{key: string, level: string, message: string}>
     * }
     */
    public function analyze(Site $site, SiteDeployPipeline $pipeline): array
    {
        $pipeline->loadMissing(['steps', 'hooks.notificationChannel']);

        $errors = [];
        $warnings = [];
        $checks = [];

        $buildSteps = $pipeline->steps
            ->where('phase', SiteDeployStep::PHASE_BUILD)
            ->sortBy('sort_order')
            ->values();
        $releaseSteps = $pipeline->steps
            ->where('phase', SiteDeployStep::PHASE_RELEASE)
            ->sortBy('sort_order')
            ->values();

        foreach ($pipeline->steps as $step) {
            if ($step->commandFor() !== null) {
                continue;
            }

            $label = $step->pillLabel();
            $phaseLabel = ($step->phase ?? SiteDeployStep::PHASE_BUILD) === SiteDeployStep::PHASE_RELEASE
                ? __('Release')
                : __('Build');
            $message = __(':label in :phase has no runnable command—add a script name or command text.', [
                'label' => $label,
                'phase' => $phaseLabel,
            ]);
            $errors[] = $message;
            $checks[] = $this->check('empty_step_command', 'error', $message);
        }

        $this->checkDuplicateStepTypes($buildSteps, SiteDeployStep::PHASE_BUILD, __('Build'), $warnings, $checks);
        $this->checkDuplicateStepTypes($releaseSteps, SiteDeployStep::PHASE_RELEASE, __('Release'), $warnings, $checks);
        $this->checkMultipleNodeInstallers($buildSteps, $warnings, $checks);
        $this->checkPhaseMisplacement($buildSteps, $releaseSteps, $warnings, $checks);
        $this->checkReleaseOrdering($releaseSteps, $warnings, $checks);
        $this->checkCacheClearVsWarm($buildSteps, $warnings, $checks);
        $this->checkNpmInstallOrder($buildSteps, $warnings, $checks);
        $this->checkScaffoldingSteps($pipeline->steps, $warnings, $checks);
        $this->checkEmptyPipeline($site, $pipeline->steps, $warnings, $checks);
        $this->checkHooks($pipeline->hooks, $pipeline->steps, $errors, $warnings, $checks);
        $this->checkLaravelReleaseSafety($site, $releaseSteps, $pipeline->hooks, $warnings, $checks);

        if ($site->deploy_strategy !== 'atomic') {
            $hasReleaseMigrate = $releaseSteps->contains(
                fn (SiteDeployStep $s) => $s->step_type === SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            );
            if ($hasReleaseMigrate) {
                $message = __('With simple (in-place) deploys, Release-phase migrations run on the live checkout after code is already updated—prefer atomic zero-downtime if you need isolated release directories.');
                $warnings[] = $message;
                $checks[] = $this->check('simple_deploy_migrations', 'warning', $message);
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $steps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkDuplicateStepTypes(Collection $steps, string $phase, string $phaseLabel, array &$warnings, array &$checks): void
    {
        $counts = $steps->groupBy('step_type')->map->count()->filter(fn (int $c) => $c > 1);

        foreach ($counts as $type => $count) {
            $label = SiteDeployStep::typeLabels()[(string) $type] ?? (string) $type;
            $message = __(':phase has :count duplicate “:label” steps—remove extras unless you intentionally run it twice.', [
                'phase' => $phaseLabel,
                'count' => $count,
                'label' => $label,
            ]);
            $warnings[] = $message;
            $check = $this->check('duplicate_step_'.$type.'_'.$phaseLabel, 'warning', $message);
            $check['meta'] = ['step_type' => (string) $type, 'phase' => $phase];
            $checks[] = $check;
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $buildSteps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkMultipleNodeInstallers(Collection $buildSteps, array &$warnings, array &$checks): void
    {
        $installers = $buildSteps
            ->pluck('step_type')
            ->filter(fn (string $t) => in_array($t, self::NODE_INSTALL_TYPES, true))
            ->unique()
            ->values();

        if ($installers->count() < 2) {
            return;
        }

        $message = __('Build lists more than one JS package manager (e.g. npm ci and yarn install)—pick the lockfile your repo actually uses.');
        $warnings[] = $message;
        $checks[] = $this->check('multiple_node_installers', 'warning', $message);
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $buildSteps
     * @param  Collection<int, SiteDeployStep>  $releaseSteps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkPhaseMisplacement(
        Collection $buildSteps,
        Collection $releaseSteps,
        array &$warnings,
        array &$checks,
    ): void {
        foreach ($buildSteps as $step) {
            if (! in_array($step->step_type, SiteDeployStep::RELEASE_STEP_TYPES, true)) {
                continue;
            }

            $message = match ($step->step_type) {
                SiteDeployStep::TYPE_ARTISAN_MIGRATE => __('“Migrate” is in Build—it normally belongs in Release (after activate) so the database updates when the new code is live.'),
                SiteDeployStep::TYPE_ARTISAN_DB_SEED => __('“DB seed” is in Build—seeding usually belongs in Release and should not run on every deploy unless intentional.'),
                default => __(':label is in Build but is usually a Release step—move it after Activate unless you have a deliberate pre-switch workflow.', [
                    'label' => $step->pillLabel(),
                ]),
            };
            $warnings[] = $message;
            $check = $this->check('release_step_in_build_'.$step->step_type, 'warning', $message);
            $check['meta'] = [
                'step_type' => (string) $step->step_type,
                'from_phase' => SiteDeployStep::PHASE_BUILD,
                'to_phase' => SiteDeployStep::PHASE_RELEASE,
            ];
            $checks[] = $check;
        }

        foreach ($releaseSteps as $step) {
            if (! in_array($step->step_type, self::BUILD_ONLY_TYPES, true)) {
                continue;
            }

            $message = __(':label is in Release—it usually runs in Build (before activate) so dependencies and assets compile in the new release directory.', [
                'label' => $step->pillLabel(),
            ]);
            $warnings[] = $message;
            $check = $this->check('build_step_in_release_'.$step->step_type, 'warning', $message);
            $check['meta'] = [
                'step_type' => (string) $step->step_type,
                'from_phase' => SiteDeployStep::PHASE_RELEASE,
                'to_phase' => SiteDeployStep::PHASE_BUILD,
            ];
            $checks[] = $check;
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $releaseSteps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkReleaseOrdering(Collection $releaseSteps, array &$warnings, array &$checks): void
    {
        if ($releaseSteps->isEmpty()) {
            return;
        }

        $indexed = $releaseSteps->values();
        $position = fn (string $type) => $indexed->search(fn (SiteDeployStep $s) => $s->step_type === $type);

        $migratePos = $position(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
        $seedPos = $position(SiteDeployStep::TYPE_ARTISAN_DB_SEED);
        $optimizePos = $position(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE);
        $queuePos = $position(SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART);
        $horizonPos = $position(SiteDeployStep::TYPE_ARTISAN_HORIZON_TERMINATE);

        if ($seedPos !== false && $migratePos === false) {
            $message = __('Release includes DB seed without migrate—add migrate first or seed may run against the wrong schema.');
            $warnings[] = $message;
            $checks[] = $this->check('seed_without_migrate', 'warning', $message);
        }

        if ($migratePos !== false && $optimizePos !== false && $optimizePos < $migratePos) {
            $message = __('“Optimize” runs before “Migrate” in Release—migrate first, then warm caches.');
            $warnings[] = $message;
            $checks[] = $this->check('optimize_before_migrate', 'warning', $message);
        }

        if ($migratePos !== false && $queuePos !== false && $queuePos < $migratePos) {
            $message = __('“Queue restart” is before “Migrate”—workers may boot against an old schema; restart queues after migrations.');
            $warnings[] = $message;
            $checks[] = $this->check('queue_before_migrate', 'warning', $message);
        }

        if ($migratePos !== false && $horizonPos !== false && $horizonPos < $migratePos) {
            $message = __('“Horizon terminate” is before “Migrate”—restart Horizon after the database is migrated.');
            $warnings[] = $message;
            $checks[] = $this->check('horizon_before_migrate', 'warning', $message);
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $buildSteps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkNpmInstallOrder(Collection $buildSteps, array &$warnings, array &$checks): void
    {
        $indexed = $buildSteps->values();
        $npmCi = $indexed->search(fn (SiteDeployStep $s) => $s->step_type === SiteDeployStep::TYPE_NPM_CI);
        $npmInstall = $indexed->search(fn (SiteDeployStep $s) => $s->step_type === SiteDeployStep::TYPE_NPM_INSTALL);

        if ($npmCi !== false && $npmInstall !== false && $npmInstall < $npmCi) {
            $message = __('“npm install” is listed before “npm ci” in Build—ci should run first when you use a lockfile.');
            $warnings[] = $message;
            $checks[] = $this->check('npm_install_before_ci', 'warning', $message);
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $buildSteps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkCacheClearVsWarm(Collection $buildSteps, array &$warnings, array &$checks): void
    {
        $clearPos = $buildSteps->search(fn (SiteDeployStep $s) => $s->step_type === SiteDeployStep::TYPE_ARTISAN_CACHE_CLEAR);
        if ($clearPos === false) {
            return;
        }

        $warmPos = $buildSteps->search(
            fn (SiteDeployStep $s) => in_array($s->step_type, self::CACHE_WARM_TYPES, true),
        );

        if ($warmPos !== false && $clearPos < $warmPos) {
            $message = __('“Cache clear” runs before cache-warming steps in Build—clearing after optimize/config cache avoids discarding warmed caches immediately.');
            $warnings[] = $message;
            $checks[] = $this->check('cache_clear_before_warm', 'warning', $message);
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $steps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkScaffoldingSteps(Collection $steps, array &$warnings, array &$checks): void
    {
        foreach ($steps as $step) {
            if (! in_array($step->step_type, self::SCAFFOLDING_TYPES, true)) {
                continue;
            }

            $message = __(':label runs on every deploy—scaffolding commands are usually one-time setup, not routine pipeline steps.', [
                'label' => $step->pillLabel(),
            ]);
            $warnings[] = $message;
            $checks[] = $this->check('scaffolding_'.$step->step_type, 'warning', $message);
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $steps
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkEmptyPipeline(Site $site, Collection $steps, array &$warnings, array &$checks): void
    {
        if ($steps->isNotEmpty()) {
            return;
        }

        if (! $site->isLaravelFrameworkDetected() && $site->runtimeKey() !== 'php') {
            return;
        }

        $message = __('This pipeline has no build or release steps—add at least Composer install or your usual build commands.');
        $warnings[] = $message;
        $checks[] = $this->check('empty_pipeline', 'warning', $message);
    }

    /**
     * @param  Collection<int, SiteDeployHook>  $hooks
     * @param  Collection<int, SiteDeployStep>  $steps
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkHooks(
        Collection $hooks,
        Collection $steps,
        array &$errors,
        array &$warnings,
        array &$checks,
    ): void {
        $stepIds = $steps->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($hooks as $hook) {
            if ($hook->hook_kind === SiteDeployHook::KIND_SHELL && trim((string) $hook->script) === '') {
                $message = __('Shell hook “:label” has no script.', ['label' => $hook->pillLabel()]);
                $errors[] = $message;
                $checks[] = $this->check('empty_shell_hook_'.$hook->id, 'error', $message);
            }

            if ($hook->hook_kind === SiteDeployHook::KIND_WEBHOOK && trim((string) $hook->webhook_url) === '') {
                $message = __('Webhook hook “:label” has no URL.', ['label' => $hook->pillLabel()]);
                $errors[] = $message;
                $checks[] = $this->check('empty_webhook_'.$hook->id, 'error', $message);
            }

            if ($hook->hook_kind === SiteDeployHook::KIND_NOTIFICATION && ! filled($hook->notification_channel_id)) {
                $message = __('Notification hook “:label” has no channel selected.', ['label' => $hook->pillLabel()]);
                $errors[] = $message;
                $checks[] = $this->check('empty_notification_'.$hook->id, 'error', $message);
            }

            if ($hook->anchor === SiteDeployHook::ANCHOR_AFTER_STEP
                && filled($hook->anchor_step_id)
                && ! in_array((string) $hook->anchor_step_id, $stepIds, true)) {
                $message = __('Hook “:label” points at a removed step—edit it or delete the hook.', ['label' => $hook->pillLabel()]);
                $errors[] = $message;
                $checks[] = $this->check('orphan_hook_'.$hook->id, 'error', $message);
            }

            if ($hook->hook_kind !== SiteDeployHook::KIND_SHELL) {
                continue;
            }

            $script = strtolower(trim((string) $hook->script));

            if (str_contains($script, 'artisan down') && $hook->anchor === SiteDeployHook::ANCHOR_AFTER_ACTIVATE) {
                $message = __('Maintenance down runs after activate—traffic already hit the new release; use before activate instead.');
                $warnings[] = $message;
                $checks[] = $this->check('maintenance_down_late_'.$hook->id, 'warning', $message);
            }

            if (str_contains($script, 'artisan up') && in_array($hook->anchor, [
                SiteDeployHook::ANCHOR_BEFORE_CLONE,
                SiteDeployHook::ANCHOR_AFTER_CLONE,
                SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
            ], true)) {
                $message = __('Maintenance up runs before the site is live—use after activate (or remove if you never ran down).');
                $warnings[] = $message;
                $checks[] = $this->check('maintenance_up_early_'.$hook->id, 'warning', $message);
            }
        }
    }

    /**
     * @param  Collection<int, SiteDeployStep>  $releaseSteps
     * @param  Collection<int, SiteDeployHook>  $hooks
     * @param  list<string>  $warnings
     * @param  list<array{key: string, level: string, message: string}>  $checks
     */
    private function checkLaravelReleaseSafety(
        Site $site,
        Collection $releaseSteps,
        Collection $hooks,
        array &$warnings,
        array &$checks,
    ): void {
        if (! $site->isLaravelFrameworkDetected()) {
            return;
        }

        $indexed = $releaseSteps->values();
        $position = fn (string $type) => $indexed->search(fn (SiteDeployStep $s) => $s->step_type === $type);

        $migratePos = $position(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
        $pretendPos = $position(SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND);
        $hasBackup = $releaseSteps->contains(
            fn (SiteDeployStep $s) => $s->step_type === SiteDeployStep::TYPE_CUSTOM
                && str_contains((string) $s->custom_command, 'dply-pre-migrate'),
        );

        if ($migratePos !== false && $pretendPos === false) {
            $message = __('Release runs Migrate without a prior “Migrate (pretend)” step—add pretend or the Laravel safety bundle to preview SQL first.');
            $warnings[] = $message;
            $checks[] = $this->check('migrate_without_pretend', 'warning', $message);
        }

        if ($migratePos !== false && $pretendPos !== false && $pretendPos > $migratePos) {
            $message = __('“Migrate (pretend)” is after “Migrate”—pretend should run first to preview pending changes.');
            $warnings[] = $message;
            $checks[] = $this->check('pretend_after_migrate', 'warning', $message);
        }

        if ($migratePos !== false && ! $hasBackup) {
            $message = __('Release includes Migrate without a pre-migrate DB snapshot step—use the Laravel safety bundle or Server → Databases → Backups.');
            $warnings[] = $message;
            $checks[] = $this->check('migrate_without_backup', 'warning', $message);
        }

        if ($migratePos !== false && $hasBackup && $pretendPos !== false) {
            $backupPos = $indexed->search(
                fn (SiteDeployStep $s) => $s->step_type === SiteDeployStep::TYPE_CUSTOM
                    && str_contains((string) $s->custom_command, 'dply-pre-migrate'),
            );
            if ($backupPos !== false && $backupPos > $pretendPos) {
                $message = __('Pre-migrate backup runs after “Migrate (pretend)”—snapshot the database before dry-run and real migrate.');
                $warnings[] = $message;
                $checks[] = $this->check('backup_after_pretend', 'warning', $message);
            }
        }

        $hasMaintenanceDown = $hooks->contains(
            fn (SiteDeployHook $h) => $h->hook_kind === SiteDeployHook::KIND_SHELL
                && $h->anchor === SiteDeployHook::ANCHOR_BEFORE_ACTIVATE
                && str_contains(strtolower((string) $h->script), 'artisan down'),
        );
        $hasMaintenanceUp = $hooks->contains(
            fn (SiteDeployHook $h) => $h->hook_kind === SiteDeployHook::KIND_SHELL
                && $h->anchor === SiteDeployHook::ANCHOR_AFTER_ACTIVATE
                && str_contains(strtolower((string) $h->script), 'artisan up'),
        );

        if ($migratePos !== false && ! $hasMaintenanceDown) {
            $message = __('Migrate in Release without a maintenance-down hook before activate—visitors may hit the app during schema changes.');
            $warnings[] = $message;
            $checks[] = $this->check('migrate_without_maintenance_down', 'warning', $message);
        }

        if ($hasMaintenanceDown && ! $hasMaintenanceUp) {
            $message = __('Maintenance down is configured without a matching “up” hook after activate.');
            $warnings[] = $message;
            $checks[] = $this->check('maintenance_down_without_up', 'warning', $message);
        }
    }

    /**
     * @return array{key: string, level: string, message: string}
     */
    private function check(string $key, string $level, string $message): array
    {
        return [
            'key' => $key,
            'level' => $level,
            'message' => $message,
        ];
    }
}
