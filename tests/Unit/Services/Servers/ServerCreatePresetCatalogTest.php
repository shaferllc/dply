<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\ServerCreatePresetCatalogTest;
use App\Services\Servers\ServerCreatePresetCatalog;
test('catalog lists the v1 presets in order', function () {
    $ids = array_column((new ServerCreatePresetCatalog)->all(), 'id');

    expect($ids)->toBe([
        'laravel',
        'rails',
        'nextjs',
        'django',
        'polyglot',
        'wordpress',
        'static',
        'database',
        'custom',
    ]);
});
test('wordpress preset uses mariadb redis php 84', function () {
    $wp = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_WORDPRESS);

    expect($wp)->not->toBeNull();
    expect($wp['role'])->toBe('application');
    expect($wp['webserver'])->toBe('nginx');
    expect($wp['php_version'])->toBe('8.4');
    expect($wp['database'])->toBe('mariadb114');
    expect($wp['cache'])->toBe('redis');
    expect($wp['runtimes'])->toBe([]);
    expect($wp['featured'])->toBeTrue();
});
test('polyglot preset carries all four non php runtimes', function () {
    $polyglot = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_POLYGLOT);

    expect($polyglot)->not->toBeNull();
    expect(array_keys($polyglot['runtimes']))->toEqualCanonicalizing(['node', 'python', 'ruby', 'go']);

    // Plus PHP through the dedicated php_version slot, since PHP uses
    // ondrej/php apt rather than mise.
    expect($polyglot['php_version'])->toBe('8.4');
    expect($polyglot['featured'])->toBeTrue();
});
test('laravel preset pins mysql 84 and redis', function () {
    $laravel = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_LARAVEL);

    expect($laravel)->not->toBeNull();
    expect($laravel['database'])->toBe('mysql84');
    expect($laravel['cache'])->toBe('redis');
    expect($laravel['php_version'])->toBe('8.4');
    expect($laravel['runtimes'])->toBe([]);
});
test('rails preset uses postgres 17 with ruby runtime', function () {
    $rails = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_RAILS);

    expect($rails)->not->toBeNull();
    expect($rails['database'])->toBe('postgres17');
    expect($rails['cache'])->toBe('redis');
    expect($rails['php_version'])->toBeNull();
    expect($rails['runtimes'])->toBe(['ruby' => '3.3']);
});
test('static preset clears php db and cache', function () {
    $static = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_STATIC);

    expect($static)->not->toBeNull();
    expect($static['role'])->toBe('static');
    expect($static['php_version'])->toBeNull();
    expect($static['database'])->toBeNull();
    expect($static['cache'])->toBeNull();
    expect($static['runtimes'])->toBe([]);
});
test('database node preset has no webserver', function () {
    $db = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_DATABASE);

    expect($db)->not->toBeNull();
    expect($db['role'])->toBe('database');
    expect($db['webserver'])->toBeNull();
    expect($db['database'])->toBe('postgres17');
});
test('custom preset is empty escape hatch', function () {
    $custom = (new ServerCreatePresetCatalog)->find(ServerCreatePresetCatalog::ID_CUSTOM);

    expect($custom)->not->toBeNull();
    expect($custom['role'])->toBe('plain');
    expect($custom['webserver'])->toBeNull();
    expect($custom['php_version'])->toBeNull();
    expect($custom['database'])->toBeNull();
    expect($custom['cache'])->toBeNull();
    expect($custom['runtimes'])->toBe([]);
    expect($custom['featured'])->toBeFalse();
});
test('to server meta for polyglot emits runtime defaults', function () {
    $meta = (new ServerCreatePresetCatalog)->toServerMeta(ServerCreatePresetCatalog::ID_POLYGLOT);

    expect($meta['preset'])->toBe('polyglot');
    expect($meta['server_role'])->toBe('application');
    expect($meta['webserver'])->toBe('nginx');
    expect($meta['php_version'])->toBe('8.4');
    expect($meta['database'])->toBe('postgres17');
    expect($meta['cache_service'])->toBe('redis');
    expect(array_keys($meta['runtime_defaults']))->toEqualCanonicalizing(['node', 'python', 'ruby', 'go']);
});
test('to server meta omits null fields', function () {
    $meta = (new ServerCreatePresetCatalog)->toServerMeta(ServerCreatePresetCatalog::ID_STATIC);

    $this->assertArrayNotHasKey('php_version', $meta);
    $this->assertArrayNotHasKey('database', $meta);
    $this->assertArrayNotHasKey('cache_service', $meta);
    $this->assertArrayNotHasKey('runtime_defaults', $meta);
});
test('to server meta returns empty for unknown preset', function () {
    expect((new ServerCreatePresetCatalog)->toServerMeta('made-up-preset'))->toBe([]);
});
test('featured presets include the polyglot pitch', function () {
    $featured = array_filter(
        (new ServerCreatePresetCatalog)->all(),
        fn (array $p) => $p['featured'],
    );

    $featuredIds = array_column($featured, 'id');
    expect($featuredIds)->toContain('polyglot');
    expect($featuredIds)->toContain('laravel');
    expect($featuredIds)->toContain('rails');
    expect($featuredIds)->toContain('nextjs');
    expect($featuredIds)->toContain('django');
});
