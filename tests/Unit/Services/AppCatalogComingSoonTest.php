<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCatalogComingSoonTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Services\Sites\AppCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The choose-app coming-soon badge used to be a blanket `kind === 'scaffold'`
 * rule, so no installer could ship without shipping all six. These pin the
 * per-app list that replaced it.
 *
 * @return array<string, bool> key => coming_soon
 */
function comingSoonMap(): array
{
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);

    // WordPress needs a mysql-family engine attached or the needs_db gate
    // hides the tile entirely and there is nothing to assert on.
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'mariadb',
        'status' => 'installed',
        'is_default' => true,
    ]);

    $tiles = app(AppCatalog::class)->forServer($server->fresh());

    return collect($tiles)->mapWithKeys(
        fn (array $t): array => [$t['key'] => (bool) ($t['coming_soon'] ?? false)]
    )->all();
}

test('wordpress is available, not coming soon', function () {
    $map = comingSoonMap();

    expect($map)->toHaveKey('wordpress')
        ->and($map['wordpress'])->toBeFalse();
});

test('installers still on the list stay gated', function () {
    $map = comingSoonMap();

    // Enabling WordPress must not have quietly enabled the other five.
    expect($map['laravel'])->toBeTrue()
        ->and($map['statamic'])->toBeTrue()
        ->and($map['symfony'])->toBeTrue()
        ->and($map['craft'])->toBeTrue()
        ->and($map['drupal'])->toBeTrue();
});

test('non-installer tiles are never coming soon', function () {
    $map = comingSoonMap();

    expect($map['git'])->toBeFalse()
        ->and($map['static'])->toBeFalse()
        ->and($map['blank'])->toBeFalse();
});

test('the gate is config driven', function () {
    config(['sites.choose_app_coming_soon' => ['wordpress']]);

    expect(comingSoonMap()['wordpress'])->toBeTrue();
});

test('both wordpress layouts are offered and available', function () {
    $map = comingSoonMap();

    // Classic (wp core download) and Bedrock (composer) are separate tiles
    // rather than a layout radio, per AppCatalog's data-driven design.
    expect($map)->toHaveKey('wordpress')
        ->and($map)->toHaveKey('wordpress-bedrock')
        ->and($map['wordpress'])->toBeFalse()
        ->and($map['wordpress-bedrock'])->toBeFalse();
});

test('each wordpress tile records which layout it is', function () {
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'mariadb',
        'status' => 'installed',
        'is_default' => true,
    ]);

    $tiles = collect(app(AppCatalog::class)->forServer($server->fresh()))
        ->keyBy('key');

    // ChooseApp persists this to meta.scaffold.layout; it is what tells the two
    // layouts apart downstream, since both are framework=wordpress.
    expect($tiles['wordpress']['wp_layout'])->toBe('classic')
        ->and($tiles['wordpress-bedrock']['wp_layout'])->toBe('bedrock')
        ->and($tiles['wordpress-bedrock']['recipe']['package'])->toBe('roots/bedrock')
        ->and($tiles['wordpress-bedrock']['web_subdir'])->toBe('/web');
});
