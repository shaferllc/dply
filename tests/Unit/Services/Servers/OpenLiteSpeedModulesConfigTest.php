<?php

declare(strict_types=1);

use App\Services\Servers\OpenLiteSpeedModulesConfig;

it('parses registered module names from httpd_config', function (): void {
    $config = <<<'CONF'
serverName example

module cache {
  enableCache 1
}

module modcompress {
  enableCompress 1
}
CONF;

    $names = app(OpenLiteSpeedModulesConfig::class)->parseRegisteredModuleNames($config);

    expect($names)->toBe(['cache', 'modcompress']);
});

it('extracts a module block by name', function (): void {
    $config = <<<'CONF'
module modgzip {
  enableGzip 1
}
CONF;

    $block = app(OpenLiteSpeedModulesConfig::class)->extractModuleBlock($config, 'modgzip');

    expect($block)->toContain('module modgzip');
    expect($block)->toContain('enableGzip');
});

it('returns known default blocks for common modules', function (): void {
    $service = app(OpenLiteSpeedModulesConfig::class);

    expect($service->defaultBlockFor('modcompress'))->toContain('enableCompress 1');
    expect($service->defaultBlockFor('unknown_module'))->toBe("module unknown_module {\n}\n");
});

it('classifies modules into filter buckets', function (): void {
    $service = app(OpenLiteSpeedModulesConfig::class);

    expect($service->classify('cache'))->toBe('perf');
    expect($service->classify('modcompress'))->toBe('perf');
    expect($service->classify('modsecurity'))->toBe('security');
    expect($service->classify('example'))->toBe('other');
});

it('marks cache as protected', function (): void {
    expect(OpenLiteSpeedModulesConfig::PROTECTED_MODULES)->toContain('cache');
});
