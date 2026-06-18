<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\TraefikCustomMiddlewaresConfig;
use App\Services\Servers\TraefikCustomRoutesConfig;
use App\Services\Servers\TraefikDashboardExposure;
use App\Services\Servers\TraefikDynamicConfigInventory;
use App\Services\Servers\TraefikEntrypointsConfig;
use App\Services\Servers\TraefikHttpServicesConfig;
use App\Services\Servers\TraefikProvidersConfig;
use App\Services\Servers\TraefikStaticConfigOptions;
use App\Services\Servers\TraefikTcpRoutesConfig;
use App\Services\Servers\TraefikUdpRoutesConfig;
use Illuminate\Support\Facades\DB;

/**
 * Traefik edge-proxy configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component to keep it maintainable. Every
 * public property/method name is unchanged, so Livewire snapshots and wire:*
 * bindings in the Blade view continue to resolve against the composed class.
 */
trait ManagesTraefikWebserver
{
    use ManagesTraefikCore;
    use ManagesTraefikEntrypointsTcp;
    use ManagesTraefikRoutesMiddlewares;
    use ManagesTraefikUdpHttpServices;

    // ---- Traefik Static Config form (Providers sub-tab on the Traefik edge proxy).
    /** @var array<string, string> */
    public array $traefik_static_form = [];

    public bool $traefik_static_loaded = false;

    public ?string $traefik_static_flash = null;

    public ?string $traefik_static_error = null;

    /** @var list<array{path: string, basename: string, size: int, modified_at: ?string}> */
    public array $traefik_dynamic_files = [];

    public bool $traefik_dynamic_loaded = false;

    public ?string $traefik_dynamic_error = null;

    /** @var array<string, string> */
    public array $traefik_providers_form = [];

    /** @var list<array{key: string, label: string, summary: string}> */
    public array $traefik_providers_configured = [];

    public bool $traefik_providers_loaded = false;

    public ?string $traefik_providers_flash = null;

    public ?string $traefik_providers_error = null;

    /** @var array{enabled: string, path: string, username: string, password: string} */
    public array $traefik_dashboard_form = [
        'enabled' => '0',
        'path' => '/traefik-dashboard',
        'username' => '',
        'password' => '',
    ];

    public bool $traefik_dashboard_loaded = false;

    public ?string $traefik_dashboard_flash = null;

    public ?string $traefik_dashboard_error = null;

    /** @var array<string, array{hosts: string, upstream: string, rule: string, middlewares: string}> */
    public array $traefik_custom_routes_form = [];

    public bool $traefik_custom_routes_loaded = false;

    public ?string $traefik_custom_routes_flash = null;

    public ?string $traefik_custom_routes_error = null;

    public bool $traefik_custom_routes_show_add = false;

    /** @var array{slug: string, hosts: string, upstream: string, rule: string, middlewares: string} */
    public array $traefik_custom_routes_new = [
        'slug' => '',
        'hosts' => '',
        'upstream' => '',
        'rule' => '',
        'middlewares' => '',
    ];

    /** @var array<string, array{type: string, prefix: string, scheme: string, header_key: string, header_value: string, users: string}> */
    public array $traefik_custom_middlewares_form = [];

    public bool $traefik_custom_middlewares_loaded = false;

    public ?string $traefik_custom_middlewares_flash = null;

    public ?string $traefik_custom_middlewares_error = null;

    public bool $traefik_custom_middlewares_show_add = false;

    /** @var array{slug: string, type: string, prefix: string, scheme: string, header_key: string, header_value: string, users: string} */
    public array $traefik_custom_middlewares_new = [
        'slug' => '',
        'type' => 'stripPrefix',
        'prefix' => '/',
        'scheme' => 'https',
        'header_key' => '',
        'header_value' => '',
        'users' => '',
    ];

    /** @var array<string, array{name: string, address: string}> */
    public array $traefik_entrypoints_form = [];

    public bool $traefik_entrypoints_loaded = false;

    public ?string $traefik_entrypoints_flash = null;

    public ?string $traefik_entrypoints_error = null;

    public bool $traefik_entrypoints_show_add = false;

    /** @var array{name: string, address: string} */
    public array $traefik_entrypoints_new = ['name' => '', 'address' => ':8080'];

    /** @var array<string, array{rule: string, entry_points: string, server_address: string}> */
    public array $traefik_tcp_routes_form = [];

    public bool $traefik_tcp_routes_loaded = false;

    public ?string $traefik_tcp_routes_flash = null;

    public ?string $traefik_tcp_routes_error = null;

    public bool $traefik_tcp_routes_show_add = false;

    /** @var array{slug: string, rule: string, entry_points: string, server_address: string} */
    public array $traefik_tcp_routes_new = ['slug' => '', 'rule' => 'HostSNI(`*`)', 'entry_points' => 'web', 'server_address' => ''];

    /** @var array<string, array{entry_points: string, server_address: string}> */
    public array $traefik_udp_routes_form = [];

    public bool $traefik_udp_routes_loaded = false;

    public ?string $traefik_udp_routes_flash = null;

    public ?string $traefik_udp_routes_error = null;

    public bool $traefik_udp_routes_show_add = false;

    /** @var array{slug: string, entry_points: string, server_address: string} */
    public array $traefik_udp_routes_new = ['slug' => '', 'entry_points' => 'web', 'server_address' => ''];

    /** @var array<string, array{servers: string}> */
    public array $traefik_http_services_form = [];

    public bool $traefik_http_services_loaded = false;

    public ?string $traefik_http_services_flash = null;

    public ?string $traefik_http_services_error = null;

    public bool $traefik_http_services_show_add = false;

    /** @var array{slug: string, servers: string} */
    public array $traefik_http_services_new = ['slug' => '', 'servers' => ''];


}
