<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Enums\DeploymentMethod;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteDeploymentSettings
{
    public string $post_deploy_command = '';

    public string $deploy_strategy = 'simple';

    /**
     * The site's effective {@see DeploymentMethod} value — the richer deploy-recipe
     * picker that supersedes the bare zero-downtime toggle. Maps back to
     * `deploy_strategy` (and keeps {@see $zero_downtime_enabled} in sync) so the
     * pipeline tabs that still read the boolean keep working.
     */
    public string $deploy_method = 'flat';

    /** Mirrors {@see Site::$deploy_strategy} `atomic` for the zero-downtime card UI. */
    public bool $zero_downtime_enabled = false;

    /** Per-deploy ephemeral SSH credentials (site meta `deploy.ephemeral_credentials`). */
    public bool $ephemeral_deploy_credentials_enabled = false;

    public bool $deploy_health_enabled = false;

    public bool $deploy_health_auto_rollback = false;

    public string $deploy_health_path = '/up';

    public int $deploy_health_expect_status = 200;

    public int $deploy_health_attempts = 5;

    public int $deploy_health_delay_ms = 500;

    /** http|https — stored in meta `deploy_health_scheme`. */
    public string $deploy_health_scheme = 'http';

    /** Target host for curl (default loopback). */
    public string $deploy_health_host = '127.0.0.1';

    /** Optional TCP port; empty = default for scheme (80/443). */
    public string $deploy_health_port = '';

    public int $releases_to_keep = 5;

    public string $nginx_extra_raw = '';

    /** Managed VM webserver: nginx FastCGI / proxy cache, Apache static Expires, etc. */
    public bool $engine_http_cache_enabled = false;

    public bool $restart_supervisor_programs_after_deploy = false;

    public string $deployment_environment = 'production';

    public bool $deploy_sync_include_peers_on_manual = true;

    public function saveZeroDowntimeDeployment(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'zero_downtime_enabled' => 'boolean',
        ]);

        $previousStrategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $newStrategy = $this->zero_downtime_enabled ? 'atomic' : 'simple';

        $updates = ['deploy_strategy' => $newStrategy];
        // This crude toggle only knows flat vs atomic. Realign deploy_method to
        // match ONLY when the strategy actually flips, so it never clobbers a
        // deliberate maintenance/recreate choice on an unrelated save.
        if ($previousStrategy !== $newStrategy) {
            $updates['deploy_method'] = DeploymentMethod::fromStrategy($newStrategy)->value;
        }

        $this->site->update($updates);
        $this->site->refresh();
        $this->deploy_strategy = $newStrategy;
        $this->deploy_method = DeploymentMethod::forSite($this->site)->value;

        $message = __('Zero downtime deployment settings saved.');

        if ($previousStrategy === $newStrategy) {
            $this->toastSuccess($message);

            return;
        }

        // Arm a one-time on-disk layout migration. The NEXT successful deploy
        // builds the new layout and archives the old one (flat checkout ⇄ atomic
        // releases) via SiteDeployLayoutMigrator — so switching the type can't
        // leave a hybrid behind. See docs/DEPLOYMENT_METHODS.md.
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['deploy_layout_migration'] = [
            'from' => $previousStrategy === 'atomic' ? 'atomic' : 'flat',
            'to' => $newStrategy === 'atomic' ? 'atomic' : 'flat',
            'armed_at' => now()->toIso8601String(),
        ];
        $this->site->forceFill(['meta' => $meta])->save();

        if ($this->shouldAutoReapplyManagedWebserverConfig()) {
            ApplySiteWebserverConfigJob::dispatch($this->site->id);
            $this->toastSuccess($message.' '.__('Webserver config queued.'));

            return;
        }

        $this->toastSuccess($message.' '.__('Use “Apply webserver config now” on the Routing tab if the document root should match this strategy.'));
    }

    /**
     * The deployment methods offerable for this site, shaped for the picker.
     * Sourced from {@see DeploymentMethod::availableForSite()} so a not-yet-built
     * or unsupported method is never listed (and so never shown-then-errored).
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function deploymentMethodOptions(): array
    {
        return array_map(
            static fn (DeploymentMethod $m): array => [
                'value' => $m->value,
                'label' => $m->label(),
                'description' => $m->description(),
            ],
            DeploymentMethod::availableForSite($this->site),
        );
    }

    /**
     * Persist the chosen {@see DeploymentMethod}: write `deploy_method`, keep the
     * legacy `deploy_strategy` / `zero_downtime_enabled` mirrors in sync, and arm
     * a one-time on-disk layout migration when the placement actually changes
     * (flat ⇄ atomic). The migration runs at the END of the next successful
     * deploy via SiteDeployLayoutMigrator — see docs/DEPLOYMENT_METHODS.md.
     */
    public function saveDeploymentMethod(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['deploy_method' => ['required', 'string']]);

        $method = DeploymentMethod::tryFrom($this->deploy_method);

        // Server-truth gate: only accept a method actually offered for this site.
        // Guards against a hidden/scaffolded value arriving from a tampered form.
        if ($method === null || ! in_array($method, DeploymentMethod::availableForSite($this->site), true)) {
            $this->deploy_method = DeploymentMethod::forSite($this->site)->value;
            $this->toastError(__('That deployment method isn’t available for this site.'));

            return;
        }

        $previousMethod = DeploymentMethod::forSite($this->site);
        $newStrategy = $method->deployStrategy();

        // The on-disk layout family the migrator actually transitions: flat
        // (in-place checkout) vs atomic (releases/ + current). blue-green / image
        // refine the atomic base but don't change this family, so they never arm a
        // migration the migrator can't perform.
        $layoutOf = static fn (DeploymentMethod $m): string => $m->deployStrategy() === 'simple' ? 'flat' : 'atomic';
        $previousLayout = $layoutOf($previousMethod);
        $newLayout = $layoutOf($method);

        $this->site->update([
            'deploy_method' => $method->value,
            'deploy_strategy' => $newStrategy,
        ]);
        $this->site->refresh();
        $this->deploy_method = $method->value;
        $this->deploy_strategy = $newStrategy;
        $this->zero_downtime_enabled = $newStrategy === 'atomic';

        $message = __('Deployment method saved.');

        if ($previousMethod === $method) {
            $this->toastSuccess($message);

            return;
        }

        // Arm the layout migration only when the on-disk layout family changes;
        // e.g. atomic → maintenance (or → blue-green) keeps the atomic tree and
        // needs no migration.
        if ($previousLayout !== $newLayout) {
            $meta = is_array($this->site->meta) ? $this->site->meta : [];
            $meta['deploy_layout_migration'] = [
                'from' => $previousLayout,
                'to' => $newLayout,
                'armed_at' => now()->toIso8601String(),
            ];
            $this->site->forceFill(['meta' => $meta])->save();
        }

        if ($this->shouldAutoReapplyManagedWebserverConfig()) {
            ApplySiteWebserverConfigJob::dispatch($this->site->id);
            $this->toastSuccess($message.' '.__('Webserver config queued.'));

            return;
        }

        $this->toastSuccess($message.' '.__('Use “Apply webserver config now” on the Routing tab if the document root should match this method.'));
    }

    public function saveEphemeralDeployCredentials(): void
    {
        $this->authorize('update', $this->site);

        if (! ephemeral_deploy_credentials_active($this->site->organization)) {
            return;
        }

        $this->validate([
            'ephemeral_deploy_credentials_enabled' => 'boolean',
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        data_set($meta, 'deploy.ephemeral_credentials', $this->ephemeral_deploy_credentials_enabled);
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();

        $this->toastSuccess(__('Ephemeral deploy credentials settings saved.'));
    }

    public function saveDeploymentSettings(): void
    {
        $this->authorize('update', $this->site);
        $this->site->refresh();
        $showPhpOctane = $this->site->shouldShowPhpOctaneRolloutSettings();
        $showOctane = $showPhpOctane && $this->site->shouldShowOctaneRuntimeUi();
        $showReverb = $showPhpOctane && $this->site->shouldShowLaravelReverbRuntimeUi();
        $showRails = $this->site->shouldShowRailsRuntimeSettings();

        $rules = [
            'releases_to_keep' => 'required|integer|min:1|max:50',
            'nginx_extra_raw' => 'nullable|string|max:16000',
            'laravel_scheduler' => 'boolean',
            'restart_supervisor_programs_after_deploy' => 'boolean',
            'deployment_environment' => 'required|string|max:32',
            'deploy_health_enabled' => 'boolean',
            'deploy_health_auto_rollback' => 'boolean',
            'deploy_health_path' => 'nullable|string|max:512',
            'deploy_health_expect_status' => 'required|integer|min:100|max:599',
            'deploy_health_attempts' => 'required|integer|min:1|max:30',
            'deploy_health_delay_ms' => 'required|integer|min:0|max:10000',
            'deploy_health_scheme' => ['required', Rule::in(['http', 'https'])],
            'deploy_health_host' => ['required', 'string', 'max:255'],
            'deploy_health_port' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_numeric($value) || (int) $value < 1 || (int) $value > 65535) {
                        $fail(__('Enter a valid port between 1 and 65535, or leave empty for the default for your scheme.'));
                    }
                },
            ],
        ];

        if ($showOctane) {
            $rules['octane_port'] = 'nullable|integer|min:1|max:65535';
            $rules['octane_server'] = ['required', Rule::in(Site::OCTANE_SERVERS)];
        }

        if ($showReverb) {
            $rules['laravel_reverb_port'] = 'nullable|integer|min:1|max:65535';
            $rules['laravel_reverb_ws_path'] = ['nullable', 'string', 'max:128'];
        }

        if ($showRails) {
            $rules['rails_env'] = 'nullable|string|max:32';
        }

        if (! $this->shouldShowSystemUserPanel()) {
            $rules['php_fpm_user'] = 'nullable|string|max:64';
        }

        $this->validate($rules);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $path = trim($this->deploy_health_path);
        if ($path === '') {
            $path = '/up';
        }
        $meta['deploy_health_enabled'] = $this->deploy_health_enabled;
        $meta['deploy_health_auto_rollback'] = $this->deploy_health_auto_rollback;
        $meta['deploy_health_path'] = $path[0] === '/' ? $path : '/'.$path;
        $meta['deploy_health_expect_status'] = $this->deploy_health_expect_status;
        $meta['deploy_health_attempts'] = $this->deploy_health_attempts;
        $meta['deploy_health_delay_ms'] = $this->deploy_health_delay_ms;
        $meta['deploy_health_scheme'] = $this->deploy_health_scheme;
        $meta['deploy_health_host'] = trim($this->deploy_health_host) !== '' ? trim($this->deploy_health_host) : '127.0.0.1';
        $meta['deploy_health_port'] = $this->deploy_health_port !== '' ? (int) $this->deploy_health_port : null;

        if ($showOctane) {
            $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
            $lo['server'] = $this->octane_server;
            $meta['laravel_octane'] = $lo;
        }

        if ($showReverb) {
            $rv = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
            $rv['port'] = $this->laravel_reverb_port !== '' ? (int) $this->laravel_reverb_port : 8080;
            $ws = trim($this->laravel_reverb_ws_path);
            $rv['ws_path'] = $ws !== '' ? $ws : '/app';
            $meta['laravel_reverb'] = $rv;
        }

        if ($showRails) {
            $railsRuntime = is_array($meta['rails_runtime'] ?? null) ? $meta['rails_runtime'] : [];
            $env = trim($this->rails_env);
            $railsRuntime['env'] = $env !== '' ? $env : 'production';
            $meta['rails_runtime'] = $railsRuntime;
        }

        $update = [
            'releases_to_keep' => $this->releases_to_keep,
            'nginx_extra_raw' => $this->nginx_extra_raw !== '' ? $this->nginx_extra_raw : null,
            'laravel_scheduler' => $this->laravel_scheduler,
            'restart_supervisor_programs_after_deploy' => $this->restart_supervisor_programs_after_deploy,
            'deployment_environment' => $this->deployment_environment,
            'meta' => $meta,
        ];

        if (! $this->shouldShowSystemUserPanel()) {
            $update['php_fpm_user'] = $this->php_fpm_user !== '' ? $this->php_fpm_user : null;
        }

        if ($showOctane) {
            $update['octane_port'] = $this->octane_port !== '' ? (int) $this->octane_port : null;
        }

        $this->site->update($update);
        $this->syncFormFromSite();
        $this->toastSuccess('Deployment / Nginx settings saved. Re-install Nginx if you changed redirects, Octane, or extra config. Re-sync server crontab for Laravel scheduler. When “Restart Supervisor after deploy” is on, Dply restarts programs for this site (and server-wide programs) after a successful deploy.');
    }

    public function saveEngineHttpCache(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Engine HTTP cache is only available for managed VM web server sites on this host.'));

            return;
        }

        $this->validate([
            'engine_http_cache_enabled' => ['boolean'],
        ]);

        $this->site->engine_http_cache_enabled = $this->engine_http_cache_enabled;
        $this->site->save();
        $this->site->refresh();
        $this->syncFormFromSite();

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess($this->engine_http_cache_enabled
            ? __('Engine HTTP cache enabled. Web server config queued.')
            : __('Engine HTTP cache disabled. Web server config queued.'));
    }
}
