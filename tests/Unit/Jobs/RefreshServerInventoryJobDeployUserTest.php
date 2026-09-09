<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\RefreshServerInventoryJobDeployUserTest;

use App\Jobs\RefreshServerInventoryJob;
use App\Models\Server;
use App\Services\Servers\ServerInventoryProbeScript;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The per-user mise blocks only run when build() is handed a deploy user. This
 * job used to omit it, so meta.manage_mise_runtimes was never written and a box
 * whose only runtime is mise-managed (node/python/ruby/go) read as runtime-less
 * in the workspace. RunsServerInventoryProbe always passed it; this path didn't.
 */
test('it probes per-user mise state as the deploy user', function () {
    $server = Server::factory()->ready()->create([
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'dply',
        'ssh_private_key' => 'fake-key',
    ]);

    $seen = [];
    $this->app->bind(ServerInventoryProbeScript::class, function () use (&$seen) {
        return new class($seen) extends ServerInventoryProbeScript
        {
            public function __construct(private &$seen) {}

            public function build(bool $extended, int $previewLines, ?string $deployUser = null): string
            {
                $this->seen[] = $deployUser;

                return 'true';
            }
        };
    });

    // SSH will fail against the fixture IP; the job catches that per candidate,
    // so the build() call above still happens and is what we're asserting on.
    (new RefreshServerInventoryJob((string) $server->id))->handle($this->app->make(ServerInventoryProbeScript::class));

    expect($seen)->toBe(['dply']);
});

test('it falls back to null when the server logs in as root', function () {
    $server = Server::factory()->ready()->create([
        'ip_address' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_private_key' => 'fake-key',
    ]);

    $seen = [];
    $this->app->bind(ServerInventoryProbeScript::class, function () use (&$seen) {
        return new class($seen) extends ServerInventoryProbeScript
        {
            public function __construct(private &$seen) {}

            public function build(bool $extended, int $previewLines, ?string $deployUser = null): string
            {
                $this->seen[] = $deployUser;

                return 'true';
            }
        };
    });

    (new RefreshServerInventoryJob((string) $server->id))->handle($this->app->make(ServerInventoryProbeScript::class));

    expect($seen)->toBe([null]);
});
