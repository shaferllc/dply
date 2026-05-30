<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Servers\CaddyModulesManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('parseModuleIds groups module metadata', function () {
    $manager = app(CaddyModulesManager::class);
    $rows = $manager->parseModuleIds("http.handlers.file_server\nhttp.matchers.host\ntls.certificates");

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['id'])->toBe('http.handlers.file_server')
        ->and($rows[0]['kind'])->toBe('handlers')
        ->and($rows[2]['kind'])->toBe('tls');
});

test('plugin path validation rejects unsafe values', function () {
    $manager = app(CaddyModulesManager::class);

    expect($manager->isValidPluginSpec('github.com/caddy-dns/cloudflare'))->toBeTrue()
        ->and($manager->isValidPluginSpec('github.com/org/repo@v1.2.3'))->toBeTrue()
        ->and($manager->isValidPluginSpec('../escape'))->toBeFalse()
        ->and($manager->isValidPluginSpec('bad path'))->toBeFalse();
});

test('manifest plugins persist on server meta', function () {
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'caddy'],
    ]);

    $manager = app(CaddyModulesManager::class);
    $manager->addPlugin($server, 'github.com/caddy-dns/cloudflare', 'v0.2.1');

    $fresh = $server->fresh();
    $plugins = $manager->manifestPlugins($fresh);

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0]['path'])->toBe('github.com/caddy-dns/cloudflare')
        ->and($plugins[0]['version'])->toBe('v0.2.1');
});

test('rebuild script includes xcaddy with flags', function () {
    $server = Server::factory()->ready()->create([
        'meta' => [
            'webserver' => 'caddy',
            'caddy_modules' => [
                'plugins' => [
                    ['path' => 'github.com/caddy-dns/cloudflare', 'version' => 'v0.2.1'],
                ],
            ],
        ],
    ]);

    $script = app(CaddyModulesManager::class)->rebuildScript($server);

    expect($script)
        ->toContain('xcaddy build')
        ->toContain('--with')
        ->toContain('github.com/caddy-dns/cloudflare@v0.2.1')
        ->toContain('validate --config /etc/caddy/Caddyfile');
});

test('enrichedManifestPlugins adds registry metadata and compile status', function () {
    Http::fake([
        'caddyserver.com/api/modules' => Http::response([
            'result' => [
                'dns.providers.cloudflare' => [[
                    'name' => 'dns.providers.cloudflare',
                    'docs' => 'Cloudflare DNS provider for ACME DNS-01.',
                    'package' => 'github.com/caddy-dns/cloudflare',
                    'repo' => 'https://github.com/caddy-dns/cloudflare',
                ]],
            ],
        ]),
    ]);

    Cache::forget('caddy.module_registry.index');

    $server = Server::factory()->ready()->create([
        'meta' => [
            'webserver' => 'caddy',
            'caddy_modules' => [
                'plugins' => [
                    ['path' => 'github.com/caddy-dns/cloudflare'],
                ],
            ],
        ],
    ]);

    $manager = app(CaddyModulesManager::class);
    $enriched = $manager->enrichedManifestPlugins($server, [
        ['id' => 'dns.providers.cloudflare', 'namespace' => 'dns.providers', 'kind' => 'dns'],
    ]);

    expect($enriched)->toHaveCount(1)
        ->and($enriched[0]['label'])->toBe('Cloudflare DNS')
        ->and($enriched[0]['description'])->toContain('Cloudflare')
        ->and($enriched[0]['module_ids'])->toContain('dns.providers.cloudflare')
        ->and($enriched[0]['compiled'])->toBeTrue();
});

test('availableCatalog hides plugins already in manifest or compiled on server', function () {
    Http::fake([
        'caddyserver.com/api/modules' => Http::response([
            'result' => [
                'layer4.app' => [[
                    'name' => 'layer4.app',
                    'docs' => 'Layer 4 app module.',
                    'package' => 'github.com/mholt/caddy-l4',
                    'repo' => 'https://github.com/mholt/caddy-l4',
                ]],
                'dns.providers.cloudflare' => [[
                    'name' => 'dns.providers.cloudflare',
                    'docs' => 'Cloudflare DNS provider.',
                    'package' => 'github.com/caddy-dns/cloudflare',
                    'repo' => 'https://github.com/caddy-dns/cloudflare',
                ]],
            ],
        ]),
    ]);

    Cache::forget('caddy.module_registry.index');

    $manager = app(CaddyModulesManager::class);
    $installed = [
        ['id' => 'layer4.app', 'namespace' => 'layer4', 'kind' => 'other'],
    ];

    $available = $manager->availableCatalog([], $installed);

    expect($available)->not->toHaveKey('github.com/mholt/caddy-l4')
        ->and($available)->toHaveKey('github.com/caddy-dns/cloudflare');
});
