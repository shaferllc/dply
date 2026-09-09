<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteSystemdUnitBuilderExecTest;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nodeUnit(string $startCommand): string
{
    $server = Server::factory()->ready()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Node,
        'runtime' => 'node',
        'start_command' => $startCommand,
        'internal_port' => 3000,
    ]);

    return (string) app(SiteSystemdUnitBuilder::class)->buildWebUnit($site->fresh(), 'dply');
}

test('a bare start command is wrapped so systemd can execute it', function () {
    // systemd applies no shell and needs an absolute ExecStart: `npm run start`
    // exits 203/EXEC.
    $unit = nodeUnit('npm run start');

    expect($unit)->toContain("ExecStart=/bin/sh -lc 'exec npm run start'");
});

test('the unit puts mise shims on PATH', function () {
    // node/npm are mise shims in the deploy user's home, never on system PATH.
    $unit = nodeUnit('npm run start');

    expect($unit)->toContain('Environment=PATH=/home/dply/.local/share/mise/shims:');
});

test('an already-absolute command is left alone', function () {
    $unit = nodeUnit('/usr/bin/node server.js');

    expect($unit)->toContain('ExecStart=/usr/bin/node server.js')
        ->and($unit)->not->toContain("/bin/sh -lc 'exec /usr/bin/node");
});
