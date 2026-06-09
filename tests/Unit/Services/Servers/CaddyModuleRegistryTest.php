<?php

declare(strict_types=1);

use App\Services\Servers\CaddyModuleRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::forget('caddy.module_registry.index');
});

test('module registry aggregates community packages from caddy api', function () {
    Http::fake([
        'caddyserver.com/api/modules' => Http::response([
            'result' => [
                'http.handlers.file_server' => [[
                    'name' => 'http.handlers.file_server',
                    'docs' => 'Standard file server.',
                    'package' => 'github.com/caddyserver/caddy/v2/modules/caddyhttp/fileserver',
                    'repo' => 'https://github.com/caddyserver/caddy',
                ]],
                'dns.providers.cloudflare' => [[
                    'name' => 'dns.providers.cloudflare',
                    'docs' => 'Cloudflare DNS provider for ACME DNS-01.',
                    'package' => 'github.com/caddy-dns/cloudflare',
                    'repo' => 'https://github.com/caddy-dns/cloudflare',
                ]],
            ],
        ]),
    ]);

    $packages = app(CaddyModuleRegistry::class)->communityPackages();

    expect($packages)->toHaveCount(1)
        ->and($packages[0]['path'])->toBe('github.com/caddy-dns/cloudflare')
        ->and($packages[0]['module_ids'])->toContain('dns.providers.cloudflare');
});

test('packageInfo returns rich metadata for a community package', function () {
    Http::fake([
        'caddyserver.com/api/modules' => Http::response([
            'result' => [
                'dns.providers.cloudflare' => [[
                    'name' => 'dns.providers.cloudflare',
                    'docs' => "Cloudflare DNS provider.\n\nEnables DNS-01 ACME challenges via the Cloudflare API.",
                    'package' => 'github.com/caddy-dns/cloudflare',
                    'repo' => 'https://github.com/caddy-dns/cloudflare',
                ]],
            ],
        ]),
    ]);

    $info = app(CaddyModuleRegistry::class)->packageInfo('github.com/caddy-dns/cloudflare');

    expect($info)->not->toBeNull()
        ->and($info['label'])->toBe('Cloudflare DNS')
        ->and($info['module_ids'])->toContain('dns.providers.cloudflare')
        ->and($info['repo'])->toBe('https://github.com/caddy-dns/cloudflare')
        ->and($info['docs_url'])->toContain('dns.providers.cloudflare');
});

test('packagesFromModuleIds maps compiled module ids back to xcaddy paths', function () {
    Http::fake([
        'caddyserver.com/api/modules' => Http::response([
            'result' => [
                'layer4.app' => [[
                    'name' => 'layer4.app',
                    'docs' => 'Layer 4 app module.',
                    'package' => 'github.com/mholt/caddy-l4',
                    'repo' => 'https://github.com/mholt/caddy-l4',
                ]],
            ],
        ]),
    ]);

    $paths = app(CaddyModuleRegistry::class)->packagesFromModuleIds(['layer4.app']);

    expect($paths)->toBe(['github.com/mholt/caddy-l4']);
});
