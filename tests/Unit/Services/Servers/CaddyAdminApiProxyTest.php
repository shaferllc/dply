<?php

declare(strict_types=1);

use App\Services\Servers\CaddyAdminApiProxy;

test('normalizePath defaults empty to config', function () {
    $proxy = app(CaddyAdminApiProxy::class);

    expect($proxy->normalizePath(''))->toBe('config')
        ->and($proxy->normalizePath('/config/'))->toBe('config')
        ->and($proxy->normalizePath('metrics'))->toBe('metrics');
});

test('guardPath allows known admin endpoints and subpaths', function () {
    $proxy = app(CaddyAdminApiProxy::class);

    foreach (['config', 'config/apps/http/servers', 'reverse_proxy/upstreams', 'metrics', 'pki/ca/local', 'id'] as $path) {
        $proxy->guardPath($path);
        expect(true)->toBeTrue();
    }
});

test('guardPath rejects traversal and unknown paths', function () {
    $proxy = app(CaddyAdminApiProxy::class);

    expect(fn () => $proxy->guardPath('../config'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $proxy->guardPath('debug/pprof'))
        ->toThrow(InvalidArgumentException::class);
});
