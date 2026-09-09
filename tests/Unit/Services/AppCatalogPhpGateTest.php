<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCatalogPhpGateTest;

use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Services\Sites\AppCatalog;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function catalogKeysFor(?string $phpVersion): array
{
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => $phpVersion],
    ]);

    $run = ServerProvisionRun::create([
        'server_id' => $server->id,
        'attempt' => 1,
        'status' => 'succeeded',
    ]);

    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Stack summary',
        'content' => '',
        'metadata' => [
            'webserver' => 'nginx',
            'php_version' => $phpVersion,
            'database' => 'postgres17',
        ],
    ]);

    ServerInstalledServices::forgetStackSummary((string) $server->id);

    return array_column(app(AppCatalog::class)->forServer($server->fresh()), 'key');
}

test('php installers are hidden on a server with no php', function () {
    $withPhp = catalogKeysFor('8.3');
    $withoutPhp = catalogKeysFor('none');

    // Every installer in the catalog is PHP; on a php_version=none box they'd
    // scaffold an app the server can never execute. Asserting on the DB-free
    // installers keeps this independent of the separate needs_db gate, which
    // hides wordpress/laravel here because the fixture has no engine attached.
    expect($withPhp)->toContain('statamic')
        ->and($withPhp)->toContain('symfony');

    expect($withoutPhp)->not->toContain('wordpress')
        ->and($withoutPhp)->not->toContain('laravel')
        ->and($withoutPhp)->not->toContain('statamic')
        ->and($withoutPhp)->not->toContain('symfony')
        ->and($withoutPhp)->not->toContain('craft')
        ->and($withoutPhp)->not->toContain('drupal');

    // Non-PHP paths stay: a Git repo or a static/blank site needs no interpreter.
    expect($withoutPhp)->toContain('git')
        ->and($withoutPhp)->toContain('static')
        ->and($withoutPhp)->toContain('blank');
});

test('the setup card hides "Install an app" when the server has no installer', function () {
    // Drives $hasAppInstallers in SiteSettingsViewData, which the general-tab
    // setup card uses to drop a shortcut that would open an empty picker.
    $hasInstallers = fn (array $keys): bool => collect($keys)
        ->intersect(['wordpress', 'laravel', 'statamic', 'symfony', 'craft', 'drupal'])
        ->isNotEmpty();

    expect($hasInstallers(catalogKeysFor('8.3')))->toBeTrue()
        ->and($hasInstallers(catalogKeysFor('none')))->toBeFalse();
});

test('a php site on a php-less server loses the PHP tab and is marked not installed', function () {
    // divineiv's exact shape: type=php, on a server provisioned php_version=none
    // with node pinned. The page used to assert PHP everywhere regardless.
    $server = Server::factory()->ready()->create([
        'meta' => [
            'webserver' => 'nginx',
            'php_version' => 'none',
            'runtime_defaults' => ['node' => '22'],
        ],
    ]);

    $run = ServerProvisionRun::create(['server_id' => $server->id, 'attempt' => 1, 'status' => 'succeeded']);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Stack summary',
        'content' => '',
        'metadata' => ['webserver' => 'nginx', 'php_version' => 'none'],
    ]);
    ServerInstalledServices::forgetStackSummary((string) $server->id);

    $site = \App\Models\Site::factory()->create([
        'server_id' => $server->id,
        'type' => \App\Enums\SiteType::Php,
        'runtime' => 'php',
    ]);

    // One predicate now drives every PHP surface: the FPM pool panel, the
    // OPcache card, the PHP limits table and the PHP tab. Before, each used its
    // own notion of "is this a PHP site" and none consulted the host.
    expect($site->fresh()->runsPhpOnItsServer())->toBeFalse();

    expect(\App\Support\SiteSettingsSidebar::runtimeTabsFor($site->fresh()))
        ->not->toHaveKey('php');

    // And PHP must not be advertised as something this server has.
    expect(array_keys($server->fresh()->availableSiteRuntimes()))
        ->toContain('node')
        ->and(array_keys($server->fresh()->availableSiteRuntimes()))->not->toContain('php');
});

test('a php site on a server that does have php keeps its panels', function () {
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);
    $run = ServerProvisionRun::create(['server_id' => $server->id, 'attempt' => 1, 'status' => 'succeeded']);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Stack summary',
        'content' => '',
        'metadata' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);
    ServerInstalledServices::forgetStackSummary((string) $server->id);

    $site = \App\Models\Site::factory()->create([
        'server_id' => $server->id,
        'type' => \App\Enums\SiteType::Php,
        'runtime' => 'php',
    ]);

    expect($site->fresh()->runsPhpOnItsServer())->toBeTrue()
        ->and(\App\Support\SiteSettingsSidebar::runtimeTabsFor($site->fresh()))->toHaveKey('php');
});

test('a node site never shows php panels regardless of host', function () {
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3', 'runtime_defaults' => ['node' => '22']],
    ]);
    $site = \App\Models\Site::factory()->create([
        'server_id' => $server->id,
        'type' => \App\Enums\SiteType::Node,
        'runtime' => 'node',
    ]);

    expect($site->fresh()->runsPhpOnItsServer())->toBeFalse();
});
