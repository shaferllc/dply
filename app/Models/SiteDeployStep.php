<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SiteDeployStep extends Model
{
    use HasUlids;

    public const TYPE_COMPOSER_INSTALL = 'composer_install';

    public const TYPE_NPM_CI = 'npm_ci';

    public const TYPE_NPM_INSTALL = 'npm_install';

    public const TYPE_NPM_RUN = 'npm_run';

    public const TYPE_YARN_INSTALL = 'yarn_install';

    public const TYPE_PNPM_INSTALL = 'pnpm_install';

    public const TYPE_BUN_INSTALL = 'bun_install';

    public const TYPE_ARTISAN_MIGRATE = 'artisan_migrate';

    public const TYPE_ARTISAN_MIGRATE_PRETEND = 'artisan_migrate_pretend';

    public const TYPE_ARTISAN_CONFIG_CACHE = 'artisan_config_cache';

    public const TYPE_ARTISAN_ROUTE_CACHE = 'artisan_route_cache';

    public const TYPE_ARTISAN_VIEW_CACHE = 'artisan_view_cache';

    public const TYPE_ARTISAN_OPTIMIZE = 'artisan_optimize';

    /** One-shot Laravel Octane scaffolding (`php artisan octane:install`). Add after composer install when adopting Octane. */
    public const TYPE_ARTISAN_OCTANE_INSTALL = 'artisan_octane_install';

    /** One-shot Laravel Reverb scaffolding (`php artisan reverb:install`). */
    public const TYPE_ARTISAN_REVERB_INSTALL = 'artisan_reverb_install';

    public const TYPE_ARTISAN_STORAGE_LINK = 'artisan_storage_link';

    public const TYPE_ARTISAN_EVENT_CACHE = 'artisan_event_cache';

    public const TYPE_ARTISAN_QUEUE_RESTART = 'artisan_queue_restart';

    public const TYPE_ARTISAN_HORIZON_TERMINATE = 'artisan_horizon_terminate';

    public const TYPE_ARTISAN_DB_SEED = 'artisan_db_seed';

    public const TYPE_ARTISAN_CACHE_CLEAR = 'artisan_cache_clear';

    public const TYPE_CUSTOM = 'custom';

    /** @return list<string> */
    public const RELEASE_STEP_TYPES = [
        self::TYPE_ARTISAN_MIGRATE,
        self::TYPE_ARTISAN_MIGRATE_PRETEND,
        self::TYPE_ARTISAN_OPTIMIZE,
        self::TYPE_ARTISAN_STORAGE_LINK,
        self::TYPE_ARTISAN_QUEUE_RESTART,
        self::TYPE_ARTISAN_HORIZON_TERMINATE,
        self::TYPE_ARTISAN_DB_SEED,
        self::TYPE_ARTISAN_CACHE_CLEAR,
    ];

    /**
     * Deploy pipeline phases (build → swap → release → restart).
     *
     * Per the strategy memo each named phase carries its own steps so
     * the deploy UI can show per-phase status, timing, and logs.
     *
     *   - BUILD   runs in releases/{id}/ before the symlink flip:
     *             dependency installs, asset builds, one-shot scaffolding.
     *   - SWAP    is dply-owned: the atomic `current` symlink flip.
     *             No user-configurable steps land here.
     *   - RELEASE runs after the swap when the new release is live but
     *             traffic might already be flowing: DB migrations,
     *             post-deploy cache priming, etc.
     *   - RESTART is dply-owned: `systemctl reload php-fpm` for PHP
     *             sites, `systemctl restart dply-site-{id}` for non-PHP.
     *             Not user-editable — preserves FPM-reload correctness.
     */
    public const PHASE_BUILD = 'build';

    public const PHASE_SWAP = 'swap';

    public const PHASE_RELEASE = 'release';

    public const PHASE_RESTART = 'restart';

    /** @return list<string> phases users can author steps in */
    public const USER_PHASES = [self::PHASE_BUILD, self::PHASE_RELEASE];

    /** @return list<string> all phases in canonical pipeline order */
    public const ALL_PHASES = [
        self::PHASE_BUILD,
        self::PHASE_SWAP,
        self::PHASE_RELEASE,
        self::PHASE_RESTART,
    ];

    protected $fillable = [
        'site_id',
        'pipeline_id',
        'sort_order',
        'step_type',
        'phase',
        'custom_command',
        'timeout_seconds',
        'managed_by_manifest',
    ];

    protected function casts(): array
    {
        return [
            'managed_by_manifest' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SiteDeployPipeline::class, 'pipeline_id');
    }

    /**
     * Default phase for each known step type — the value the user sees
     * pre-selected when adding a step in the UI. Migrations / tests can
     * call this to keep step→phase mapping consistent.
     */
    public static function defaultPhaseFor(string $stepType): string
    {
        return in_array($stepType, self::RELEASE_STEP_TYPES, true)
            ? self::PHASE_RELEASE
            : self::PHASE_BUILD;
    }

    /** @return list<string> */
    public static function userPhases(): array
    {
        return self::USER_PHASES;
    }

    /** @return list<string> */
    public static function allPhases(): array
    {
        return self::ALL_PHASES;
    }

    public function scopePhase($query, string $phase)
    {
        return $query->where('phase', $phase);
    }

    /**
     * Tailwind class fragment for the per-phase pill in the deploy-step
     * UI. Lives on the model so the view stays free of inline match()
     * expressions (Blade's @php(...) inline form chokes on the nested
     * parens + braces of a match expression — the block form has its
     * own gotchas — so the cleanest path is to compute here).
     */
    public function phaseBadgeClass(): string
    {
        return match ($this->phase ?? self::PHASE_BUILD) {
            self::PHASE_RELEASE => 'bg-emerald-100 text-emerald-900',
            self::PHASE_SWAP => 'bg-violet-100 text-violet-900',
            self::PHASE_RESTART => 'bg-amber-100 text-amber-900',
            default => 'bg-sky-100 text-sky-900',
        };
    }

    /**
     * Resolve this step into the shell command the deploy runner should
     * execute. Built-in step types map to canonical command strings;
     * TYPE_NPM_RUN appends the user-supplied script name; TYPE_CUSTOM
     * uses custom_command verbatim.
     *
     * Returns null when the step can't produce a runnable command (a
     * TYPE_CUSTOM row with empty custom_command, an unknown type, or a
     * TYPE_NPM_RUN with no script name) so the runner can skip without
     * generating an obviously-broken shell line.
     */
    public function commandFor(): ?string
    {
        return match ($this->step_type) {
            self::TYPE_COMPOSER_INSTALL => 'composer install --no-dev --optimize-autoloader',
            self::TYPE_NPM_CI => 'npm ci',
            self::TYPE_NPM_INSTALL => 'npm install',
            self::TYPE_NPM_RUN => trim((string) $this->custom_command) !== ''
                ? 'npm run '.trim((string) $this->custom_command)
                : null,
            self::TYPE_YARN_INSTALL => 'yarn install --frozen-lockfile',
            self::TYPE_PNPM_INSTALL => 'pnpm install --frozen-lockfile',
            self::TYPE_BUN_INSTALL => 'bun install --frozen-lockfile',
            self::TYPE_ARTISAN_MIGRATE => 'php artisan migrate --force',
            self::TYPE_ARTISAN_MIGRATE_PRETEND => 'php artisan migrate --pretend --no-interaction',
            self::TYPE_ARTISAN_CONFIG_CACHE => 'php artisan config:cache',
            self::TYPE_ARTISAN_ROUTE_CACHE => 'php artisan route:cache',
            self::TYPE_ARTISAN_VIEW_CACHE => 'php artisan view:cache',
            self::TYPE_ARTISAN_OPTIMIZE => 'php artisan optimize',
            self::TYPE_ARTISAN_OCTANE_INSTALL => 'php artisan octane:install --no-interaction',
            self::TYPE_ARTISAN_REVERB_INSTALL => 'php artisan reverb:install --no-interaction',
            self::TYPE_ARTISAN_STORAGE_LINK => 'php artisan storage:link',
            self::TYPE_ARTISAN_EVENT_CACHE => 'php artisan event:cache',
            self::TYPE_ARTISAN_QUEUE_RESTART => 'php artisan queue:restart',
            // Terminate gracefully, then VERIFY the process supervisor brought
            // Horizon back. `horizon:terminate` exits 0 (a clean exit), so a unit
            // with Restart=on-failure would silently leave Horizon dead after the
            // deploy. We only check when Horizon was running beforehand (skips a
            // false alarm on first deploy) and only WARN — never fail the deploy.
            self::TYPE_ARTISAN_HORIZON_TERMINATE => 'BEFORE=$(pgrep -fc "artisan horizon" 2>/dev/null || echo 0); '
                .'php artisan horizon:terminate; '
                .'if [ "$BEFORE" -gt 0 ] 2>/dev/null; then '
                .'for _i in 1 2 3 4 5 6 7 8; do sleep 1; pgrep -f "artisan horizon" >/dev/null 2>&1 && break; done; '
                .'pgrep -f "artisan horizon" >/dev/null 2>&1 '
                .'&& echo "[dply] Horizon is running after terminate." '
                .'|| echo "[dply] WARNING: Horizon did not restart after horizon:terminate — verify its systemd unit is enabled with Restart=always."; '
                .'else echo "[dply] Horizon was not running before terminate; skipping restart check."; fi',
            self::TYPE_ARTISAN_DB_SEED => 'php artisan db:seed --force',
            self::TYPE_ARTISAN_CACHE_CLEAR => 'php artisan cache:clear',
            self::TYPE_CUSTOM => trim((string) $this->custom_command) !== ''
                ? trim((string) $this->custom_command)
                : null,
            default => null,
        };
    }

    /** Short label for pipeline pill UI (execution order strip). */
    public function pillLabel(): string
    {
        return match ($this->step_type) {
            self::TYPE_COMPOSER_INSTALL => __('Composer install'),
            self::TYPE_NPM_CI => __('npm ci'),
            self::TYPE_NPM_INSTALL => __('npm install'),
            self::TYPE_NPM_RUN => trim((string) $this->custom_command) !== ''
                ? __('npm run :script', ['script' => trim((string) $this->custom_command)])
                : __('npm run'),
            self::TYPE_YARN_INSTALL => __('yarn install'),
            self::TYPE_PNPM_INSTALL => __('pnpm install'),
            self::TYPE_BUN_INSTALL => __('bun install'),
            self::TYPE_ARTISAN_MIGRATE => __('Migrate'),
            self::TYPE_ARTISAN_MIGRATE_PRETEND => __('Migrate (pretend)'),
            self::TYPE_ARTISAN_CONFIG_CACHE => __('Config cache'),
            self::TYPE_ARTISAN_ROUTE_CACHE => __('Route cache'),
            self::TYPE_ARTISAN_VIEW_CACHE => __('View cache'),
            self::TYPE_ARTISAN_OPTIMIZE => __('Optimize'),
            self::TYPE_ARTISAN_OCTANE_INSTALL => __('Octane install'),
            self::TYPE_ARTISAN_REVERB_INSTALL => __('Reverb install'),
            self::TYPE_ARTISAN_STORAGE_LINK => __('Storage link'),
            self::TYPE_ARTISAN_EVENT_CACHE => __('Event cache'),
            self::TYPE_ARTISAN_QUEUE_RESTART => __('Queue restart'),
            self::TYPE_ARTISAN_HORIZON_TERMINATE => __('Horizon terminate'),
            self::TYPE_ARTISAN_DB_SEED => __('DB seed'),
            self::TYPE_ARTISAN_CACHE_CLEAR => __('Cache clear'),
            self::TYPE_CUSTOM => trim((string) $this->custom_command) !== ''
                ? Str::limit(trim((string) $this->custom_command), 36)
                : __('Custom command'),
            default => $this->step_type,
        };
    }

    public static function needsCustomCommand(string $stepType): bool
    {
        return in_array($stepType, [self::TYPE_NPM_RUN, self::TYPE_CUSTOM], true);
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_COMPOSER_INSTALL => 'Composer install (no dev)',
            self::TYPE_NPM_CI => 'npm ci',
            self::TYPE_NPM_INSTALL => 'npm install',
            self::TYPE_NPM_RUN => 'npm run … (script in field below)',
            self::TYPE_YARN_INSTALL => 'yarn install --frozen-lockfile',
            self::TYPE_PNPM_INSTALL => 'pnpm install --frozen-lockfile',
            self::TYPE_BUN_INSTALL => 'bun install --frozen-lockfile',
            self::TYPE_ARTISAN_MIGRATE => 'php artisan migrate --force',
            self::TYPE_ARTISAN_MIGRATE_PRETEND => 'php artisan migrate --pretend --no-interaction',
            self::TYPE_ARTISAN_CONFIG_CACHE => 'php artisan config:cache',
            self::TYPE_ARTISAN_ROUTE_CACHE => 'php artisan route:cache',
            self::TYPE_ARTISAN_VIEW_CACHE => 'php artisan view:cache',
            self::TYPE_ARTISAN_OPTIMIZE => 'php artisan optimize',
            self::TYPE_ARTISAN_OCTANE_INSTALL => 'php artisan octane:install --no-interaction',
            self::TYPE_ARTISAN_REVERB_INSTALL => 'php artisan reverb:install --no-interaction',
            self::TYPE_ARTISAN_STORAGE_LINK => 'php artisan storage:link',
            self::TYPE_ARTISAN_EVENT_CACHE => 'php artisan event:cache',
            self::TYPE_ARTISAN_QUEUE_RESTART => 'php artisan queue:restart',
            self::TYPE_ARTISAN_HORIZON_TERMINATE => 'php artisan horizon:terminate',
            self::TYPE_ARTISAN_DB_SEED => 'php artisan db:seed --force',
            self::TYPE_ARTISAN_CACHE_CLEAR => 'php artisan cache:clear',
            self::TYPE_CUSTOM => 'Custom shell command',
        ];
    }
}
