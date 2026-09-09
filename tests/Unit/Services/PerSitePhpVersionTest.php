<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PerSitePhpVersionTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SitePhpCliGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A server hosts many sites and each pins its own PHP. The "upgrade PHP" fix
 * used to also set the server-wide CLI default, so upgrading one site to 8.5
 * changed the PHP every other site's composer step ran under — breaking a
 * neighbour that needs 8.4.
 */
function phpSiteOn(Server $server, string $version): Site
{
    return Site::factory()->create([
        'server_id' => $server->id,
        'type' => \App\Enums\SiteType::Php,
        'runtime' => 'php',
        'runtime_version' => $version,
    ]);
}

test('each site pins its own php binary', function () {
    $server = Server::factory()->ready()->create();

    $guard = app(SitePhpCliGuard::class);

    $legacy = $guard->prefix(phpSiteOn($server, '8.4')->fresh());
    $modern = $guard->prefix(phpSiteOn($server, '8.5')->fresh());

    // Two sites on ONE server, each pinned to its own interpreter.
    expect($legacy)->toContain('/usr/bin/php8.4')
        ->and($legacy)->not->toContain('/usr/bin/php8.5')
        ->and($modern)->toContain('/usr/bin/php8.5')
        ->and($modern)->not->toContain('/usr/bin/php8.4');
});

test('a missing version is installed for that site rather than failing', function () {
    $server = Server::factory()->ready()->create();

    $prefix = app(SitePhpCliGuard::class)->prefix(phpSiteOn($server, '8.5')->fresh());

    expect($prefix)->toContain('php8.5-cli')
        ->and($prefix)->toContain('is required for this site');
});

test('a non-php site gets no pin', function () {
    $server = Server::factory()->ready()->create();

    $node = Site::factory()->create([
        'server_id' => $server->id,
        'type' => \App\Enums\SiteType::Node,
        'runtime' => 'node',
    ]);

    expect(app(SitePhpCliGuard::class)->prefix($node->fresh()))->toBe('');
});

test('the upgrade fix no longer touches the server-wide CLI default', function () {
    $source = file_get_contents(base_path('app/Modules/Remediations/Services/Actions/UpgradePhpAction.php'));

    // It must still install the version and repoint the site...
    expect($source)->toContain("'install'")
        ->and($source)->toContain('runtime_version');

    // ...but never flip the default every other site's build runs under.
    expect($source)->not->toContain("'set_cli_default'");
});
