<?php

declare(strict_types=1);

use App\Services\Servers\OpenLiteSpeedHttpdConfigPreserver;

it('extracts module blocks from httpd config', function (): void {
    $config = <<<'CONF'
serverName example

module cache {
  enableCache 1
  maxCacheSize 200M
}

module modsecurity {
  enabled 1
}
CONF;

    $blocks = app(OpenLiteSpeedHttpdConfigPreserver::class)->extractModuleBlocks($config);

    expect($blocks)->toHaveKeys(['cache', 'modsecurity']);
    expect($blocks['cache'])->toContain('maxCacheSize 200M');
    expect($blocks['modsecurity'])->toContain('enabled 1');
});

it('merges operator-tuned module blocks into generated config', function (): void {
    $generated = <<<'CONF'
# Managed by Dply
module cache {
  enableCache 1
}
CONF;

    $existing = <<<'CONF'
module cache {
  enableCache 1
  maxCacheSize 512M
  checkPrivateCache 1
}
CONF;

    $merged = app(OpenLiteSpeedHttpdConfigPreserver::class)->merge($generated, $existing);

    expect($merged)->toContain('maxCacheSize 512M');
    expect($merged)->toContain('checkPrivateCache 1');
    expect($merged)->not->toContain("maxCacheSize 512M\n  checkPrivateCache 1\n}\n\nmodule cache");
});

it('appends modules missing from generated config', function (): void {
    $generated = <<<'CONF'
# Managed by Dply
module cache {
  enableCache 1
}
CONF;

    $existing = <<<'CONF'
module modsecurity {
  enabled 1
}
CONF;

    $merged = app(OpenLiteSpeedHttpdConfigPreserver::class)->merge($generated, $existing);

    expect($merged)->toContain('module modsecurity');
    expect($merged)->toContain('enabled 1');
});

it('returns generated config unchanged when existing has no modules', function (): void {
    $generated = "# Managed by Dply\nmodule cache { enableCache 1 }\n";
    $existing = "serverName example\n";

    $merged = app(OpenLiteSpeedHttpdConfigPreserver::class)->merge($generated, $existing);

    expect($merged)->toBe($generated);
});
