<?php

namespace Tests\Feature\ServiceRoleToolCatalogTest;

use App\Models\Server;
use App\Services\Servers\ServerManageToolsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function toolSlugsFor(?string $role): array
{
    $server = Server::factory()->ready()->create([
        'meta' => $role === null ? [] : ['server_role' => $role],
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);

    return collect($report['catalog_rows'])->pluck('slug')->all();
}

test('an app server still gets the developer tooling', function () {
    $slugs = toolSlugsFor(null);

    expect($slugs)->toContain('mise')
        ->and($slugs)->toContain('git')
        ->and($slugs)->toContain('docker');
});

test('a database server is not offered mise, composer or git', function () {
    $slugs = toolSlugsFor('database');

    // Nothing to pin a runtime for, nothing to composer install, no repo to clone.
    expect($slugs)->not->toContain('mise')
        ->and($slugs)->not->toContain('composer')
        ->and($slugs)->not->toContain('git')
        ->and($slugs)->not->toContain('docker')
        ->and($slugs)->not->toContain('wp_cli');
});

test('the runtimes panel has no hero tool to hang off on a database server', function () {
    $server = Server::factory()->ready()->create(['meta' => ['server_role' => 'database']]);

    // hero_tool null is what the view keys the Runtimes tab off — with mise gone
    // the tab would otherwise open an empty panel.
    expect(app(ServerManageToolsReport::class)->build($server)['hero_tool'])->toBeNull();
});

test('cache and load balancer roles are gated the same way', function () {
    foreach (['redis', 'valkey', 'load_balancer'] as $role) {
        $slugs = toolSlugsFor($role);

        expect($slugs)->not->toContain('mise', "mise leaked into the {$role} catalog")
            ->and($slugs)->not->toContain('composer', "composer leaked into the {$role} catalog")
            ->and($slugs)->not->toContain('git', "git leaked into the {$role} catalog");
    }
});
