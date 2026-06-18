<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Enums\SiteType;
use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\ServerManageAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CaddyCustomRoutesConfig;
use App\Services\Servers\CaddyGlobalOptionsConfig;
use App\Services\Servers\CaddyModulesManager;
use App\Services\Servers\OpenLiteSpeedVhostsConfig;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\SiteCaddyProvisioner;
use App\Support\Servers\CaddyPhpFpmUpstreamAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Caddy webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesCaddyWebserver
{
    use ManagesCaddyCustomRoutes;
    use ManagesCaddyGlobalsSnippets;
    use ManagesCaddyModules;

    // ---- Caddy Global Options form (Admin sub-tab on the Caddy engine).
    /** @var array<string, string> */
    public array $caddy_globals_form = [];

    public bool $caddy_globals_loaded = false;

    public ?string $caddy_globals_flash = null;

    public ?string $caddy_globals_error = null;

    // ---- Caddy Snippets form (Snippets sub-tab on the Caddy engine).
    /** @var array<string, string> Snippet name → body text */
    public array $caddy_snippets_form = [];

    public bool $caddy_snippets_loaded = false;

    public ?string $caddy_snippets_flash = null;

    public ?string $caddy_snippets_error = null;

    public bool $caddy_snippets_show_add = false;

    /** @var array<string, string> */
    public array $caddy_snippets_new = ['name' => '', 'body' => ''];

    // ---- Caddy Modules (Modules sub-tab on the Caddy engine).
    /**
     * @var list<array{id: string, namespace: string, kind: string}>
     */
    public array $caddy_modules_installed = [];

    /**
     * @var list<array{
     *     path: string,
     *     version: string,
     *     label: string,
     *     description: string,
     *     repo: string,
     *     docs_url: string,
     *     module_ids: list<string>,
     *     compiled: bool,
     * }>
     */
    public array $caddy_modules_plugins = [];

    public bool $caddy_modules_loaded = false;

    public ?string $caddy_modules_flash = null;

    public ?string $caddy_modules_error = null;

    public string $caddy_modules_filter = 'all';

    public string $caddy_modules_search = '';

    public bool $caddy_modules_show_add = false;

    /** @var array{path: string, version: string} */
    public array $caddy_modules_new = ['path' => '', 'version' => ''];

    public ?string $caddy_modules_caddy_version = null;

    public bool $caddy_modules_custom_binary = false;

    public bool $caddy_modules_show_browse = false;

    public string $caddy_modules_browse_search = '';

    public ?string $caddy_modules_browse_error = null;

    /** @var array<string, array{label: string, description: string}> */
    public array $caddy_modules_available_catalog = [];

    /**
     * @var list<array{
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     * }>
     */
    public array $caddy_modules_browse_packages = [];

    /**
     * Custom Caddy routes keyed by slug → site block fields.
     *
     * @var array<string, array{hosts: string, root: string, upstream: string}>
     */
    public array $caddy_custom_routes_form = [];

    public bool $caddy_custom_routes_loaded = false;

    public ?string $caddy_custom_routes_flash = null;

    public ?string $caddy_custom_routes_error = null;

    public bool $caddy_custom_routes_show_add = false;

    /** @var array{slug: string, hosts: string, root: string, upstream: string} */
    public array $caddy_custom_routes_new = [
        'slug' => '',
        'hosts' => '',
        'root' => '',
        'upstream' => '',
    ];


    /**
     * Repair a down Caddy unix upstream to PHP-FPM (start FPM, socket access, reload Caddy).
     * Invoked from the Upstreams live-state table after modal confirmation.
     */
    public function repairCaddyPhpFpmUpstream(string $upstreamAddress): void
    {
        $phpManager = app(ServerPhpManager::class);
        $installed = $phpManager->probeInstalledVersionIds($this->server);
        $versions = CaddyPhpFpmUpstreamAddress::repairPhpVersions(
            $upstreamAddress,
            $installed,
            $phpManager->probeLatestInstalledVersion($this->server),
        );

        if ($versions['upstream'] === null && ! CaddyPhpFpmUpstreamAddress::isPhpFpmSocket($upstreamAddress)) {
            $this->remote_error = __('Could not determine PHP version from this upstream.');

            return;
        }

        $this->allowlistedActionPhpVersion = $versions['primary'];
        $this->allowlistedActionUpstreamPhpVersion = $versions['needs_config_update'] ? $versions['upstream'] : null;

        try {
            $this->runAllowlistedAction('repair_caddy_php_fpm_upstream');
            if ($this->remote_error === null && ($this->manageRemoteTaskId === null || $this->manageRemoteTaskId === '')) {
                $this->reapplyCaddySiteConfigsAfterPhpRepair();
            }
        } finally {
            $this->allowlistedActionPhpVersion = null;
            $this->allowlistedActionUpstreamPhpVersion = null;
            $this->allowlistedActionPhpVersionFallback = null;
        }
    }

    protected function reapplyCaddySiteConfigsAfterPhpRepair(): void
    {
        if (strtolower((string) data_get($this->server->meta, 'webserver', 'nginx')) !== 'caddy') {
            return;
        }

        $provisioner = app(SiteCaddyProvisioner::class);

        foreach ($this->server->sites()->get() as $site) {
            if ($site->type === SiteType::Custom) {
                continue;
            }

            try {
                $provisioner->provision($site->fresh());
            } catch (\Throwable $e) {
                $this->toastError(__('Could not re-apply Caddy config for :site: :error', [
                    'site' => $site->name,
                    'error' => mb_substr($e->getMessage(), 0, 120),
                ]));
            }
        }
    }


}
