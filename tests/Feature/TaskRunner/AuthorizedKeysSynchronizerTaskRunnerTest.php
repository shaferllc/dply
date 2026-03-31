<?php

namespace Tests\Feature\TaskRunner;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerAuthorizedKeysAuditLogger;
use App\Services\Servers\ServerAuthorizedKeysHealthCheck;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class AuthorizedKeysSynchronizerTaskRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function validPrivateKey(): string
    {
        return file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem'));
    }

    public function test_sync_writes_file_from_panel_keys_without_merging_remote_file(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '203.0.113.50',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $panelKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0';

        ServerAuthorizedKey::create([
            'server_id' => $server->id,
            'public_key' => $panelKey,
            'name' => 'test',
        ]);

        $expectedB64 = base64_encode($panelKey."\n");

        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script) use ($server, $expectedB64) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys ('.$server->ssh_user.')'
                    && str_contains($script, $expectedB64)
                    && str_contains($script, 'DPLY_AUTH_EXIT');
            })
            ->andReturn(ProcessOutput::make('ok\nDPLY_AUTH_EXIT:0')->setExitCode(0));

        $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

        $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
            $mock->shouldReceive('record')->andReturnNull();
        });
        $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
            $mock->shouldReceive('run')->never();
        });

        Event::fake();

        $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
        $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

        $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    }

    public function test_sync_with_no_keys_still_writes_empty_file_for_ssh_user(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '203.0.113.50',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys ('.$server->ssh_user.')'
                    && str_contains($script, 'DPLY_AUTH_EXIT')
                    && str_contains($script, 'base64');
            })
            ->andReturn(ProcessOutput::make('ok\nDPLY_AUTH_EXIT:0')->setExitCode(0));

        $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

        $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
            $mock->shouldReceive('record')->andReturnNull();
        });
        $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
            $mock->shouldReceive('run')->never();
        });

        Event::fake();

        $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
        $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

        $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    }
}
