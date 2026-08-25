<?php

declare(strict_types=1);

namespace Tests\Unit\Models\ServerDefaultSiteRuntimeTest;

use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function serverWith(?string $phpVersion, array $runtimeDefaults = [], bool $withStackSummary = true): Server
{
    $server = Server::factory()->ready()->create([
        'meta' => [
            'webserver' => 'nginx',
            'php_version' => $phpVersion,
            'runtime_defaults' => $runtimeDefaults,
        ],
    ]);

    if ($withStackSummary) {
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
            'metadata' => ['webserver' => 'nginx', 'php_version' => $phpVersion],
        ]);
    }

    ServerInstalledServices::forgetStackSummary((string) $server->id);

    return $server->fresh();
}

test('a php box still defaults to php', function () {
    expect(serverWith('8.3')->defaultSiteRuntime())->toBe(['php', null]);
});

test('a node box defaults to node at its pinned version', function () {
    // The Next.js / Node API preset: php_version=none, runtime_defaults={node:22}.
    expect(serverWith('none', ['node' => '22'])->defaultSiteRuntime())->toBe(['node', '22']);
});

test('a box with neither php nor node defaults to static', function () {
    // A blank site only serves a splash page, so static is the honest fallback
    // rather than claiming an interpreter the server does not have.
    expect(serverWith('none', ['python' => '3.12'])->defaultSiteRuntime())->toBe(['static', null]);
    expect(serverWith('none')->defaultSiteRuntime())->toBe(['static', null]);
});

test('it fails open to php when the stack summary has not landed yet', function () {
    // Servers we cannot read keep exactly the behaviour they have today.
    $server = serverWith('none', ['node' => '22'], withStackSummary: false);

    expect($server->defaultSiteRuntime())->toBe(['php', null]);
});
