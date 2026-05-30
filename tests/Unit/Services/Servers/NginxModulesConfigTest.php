<?php

declare(strict_types=1);

use App\Services\Servers\NginxModulesConfig;

test('parseReadOutput merges available, apt, and enabled modules', function () {
    $output = <<<'OUT'
---DPLY_LAYOUT---
debian
---DPLY_AVAILABLE---
50-mod-http-image-filter|modules/ngx_http_image_filter_module.so|libnginx-mod-http-image-filter|1
---DPLY_ENABLED---
50-mod-http-image-filter
---DPLY_APT---
libnginx-mod-http-geoip
libnginx-mod-http-image-filter
---DPLY_BUILTIN---
nginx version: nginx/1.24.0
configure arguments: --with-http_ssl_module --with-http_v2_module
OUT;

    $config = app(NginxModulesConfig::class);
    $reflection = new ReflectionClass($config);
    $method = $reflection->getMethod('parseReadOutput');
    $method->setAccessible(true);
    /** @var array{modules: list<array<string, mixed>>, builtins: list<array{name: string, type: string}>, supports_dynamic: bool, unreadable: bool} $result */
    $result = $method->invoke($config, $output);

    expect($result['supports_dynamic'])->toBeTrue()
        ->and($result['unreadable'])->toBeFalse()
        ->and($result['modules'])->toHaveCount(2);

    $image = collect($result['modules'])->firstWhere('name', 'mod-http-image-filter');
    expect($image)->not->toBeNull()
        ->and($image['enabled'])->toBeTrue()
        ->and($image['installed'])->toBeTrue()
        ->and($image['package'])->toBe('libnginx-mod-http-image-filter');

    $geoip = collect($result['modules'])->firstWhere('name', 'mod-http-geoip');
    expect($geoip)->not->toBeNull()
        ->and($geoip['enabled'])->toBeFalse()
        ->and($geoip['installed'])->toBeFalse()
        ->and($geoip['package'])->toBe('libnginx-mod-http-geoip');

    expect($result['builtins'])->not->toBeEmpty();
});

test('packageToModuleStem maps libnginx-mod apt names', function () {
    $config = app(NginxModulesConfig::class);
    $reflection = new ReflectionClass($config);
    $method = $reflection->getMethod('packageToModuleStem');
    $method->setAccessible(true);

    expect($method->invoke($config, 'libnginx-mod-http-brotli'))->toBe('mod-http-brotli');
});
