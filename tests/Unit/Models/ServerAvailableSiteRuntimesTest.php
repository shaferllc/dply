<?php

declare(strict_types=1);

namespace Tests\Unit\Models\ServerAvailableSiteRuntimesTest;

use App\Models\Server;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function serverMeta(array $meta): Server
{
    $server = Server::factory()->ready()->create(['meta' => $meta]);
    ServerInstalledServices::forgetStackSummary((string) $server->id);

    return $server->fresh();
}

test('the real mise inventory wins over the wizard pin', function () {
    // runtime_defaults is operator intent; manage_mise_runtimes is what the
    // probe actually found on the box, so the probed version is the one offered.
    $server = serverMeta([
        'php_version' => 'none',
        'runtime_defaults' => ['node' => '22'],
        'manage_mise_runtimes' => ['node' => ['versions' => ['20.16.0', '22.23.2'], 'active' => '22.23.2']],
    ]);

    expect($server->availableSiteRuntimes()['node'])->toBe('22.23.2');
});

test('it falls back to runtime_defaults when the probe has not written mise state', function () {
    // divineiv's exact shape before the RefreshServerInventoryJob fix ships:
    // no manage_mise_runtimes at all, but the Node preset pinned node 22.
    $server = serverMeta([
        'php_version' => 'none',
        'runtime_defaults' => ['node' => '22'],
    ]);

    expect($server->availableSiteRuntimes())->toHaveKey('node')
        ->and($server->availableSiteRuntimes()['node'])->toBe('22');
});

test('php is offered only on positive evidence, never on a guess', function () {
    // Strict on purpose, unlike the fail-open installer gate: picking php writes
    // a fastcgi_pass vhost, so a host we cannot confirm has PHP must not offer
    // it. Neither of these servers has a stack_summary naming php.
    $node = serverMeta(['php_version' => 'none', 'runtime_defaults' => ['node' => '22']]);
    expect($node->availableSiteRuntimes())->not->toHaveKey('php');

    $unknown = serverMeta(['runtime_defaults' => []]);
    expect($unknown->availableSiteRuntimes())->not->toHaveKey('php');
});

test('static is always available', function () {
    $server = serverMeta(['php_version' => 'none']);

    expect($server->availableSiteRuntimes())->toHaveKey('static');
});
