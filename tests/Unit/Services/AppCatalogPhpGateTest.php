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
