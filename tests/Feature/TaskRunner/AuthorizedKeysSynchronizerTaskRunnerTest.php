<?php

namespace Tests\Feature\TaskRunner;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AuthorizedKeysSynchronizerTaskRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function validPrivateKey(): string
    {
        return file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem'));
    }

    public function test_sync_calls_remote_runner_read_then_write(): void
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

        ServerAuthorizedKey::create([
            'server_id' => $server->id,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0',
            'name' => 'test',
        ]);

        $server->load('authorizedKeys');

        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($srv, $name, $bash, $timeout) use ($server) {
                return $srv->is($server)
                    && $name === 'Read authorized_keys'
                    && str_contains($bash, 'authorized_keys')
                    && $timeout === 30;
            })
            ->andReturn(ProcessOutput::make("existing-key\n")->setExitCode(0));

        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys'
                    && str_contains($script, 'DPLY_AUTH_EXIT')
                    && str_contains($script, 'base64');
            })
            ->andReturn(ProcessOutput::make('ok\nDPLY_AUTH_EXIT:0')->setExitCode(0));

        $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

        $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
        $out = $sync->sync($server->fresh(['authorizedKeys']));

        $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    }
}
