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
use phpseclib3\Crypt\RSA;
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
        config()->set('server_ssh_keys.use_root_ssh', false);

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

    public function test_sync_with_no_keys_still_writes_provisioned_key_for_ssh_user(): void
    {
        config()->set('server_ssh_keys.use_root_ssh', false);

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

        $expectedProvisionedKey = $server->openSshPublicKeyFromPrivate();
        $expectedB64 = base64_encode($expectedProvisionedKey."\n");

        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script) use ($server, $expectedB64) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys ('.$server->ssh_user.')'
                    && str_contains($script, $expectedB64)
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

    public function test_sync_preserves_server_provisioned_key_for_login_user(): void
    {
        config()->set('server_ssh_keys.use_root_ssh', false);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '203.0.113.50',
            'ssh_user' => 'dply',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $panelKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0';

        ServerAuthorizedKey::create([
            'server_id' => $server->id,
            'public_key' => $panelKey,
            'name' => 'test',
        ]);

        $expectedProvisionedKey = $server->openSshPublicKeyFromPrivate();
        $expectedLines = [$panelKey, $expectedProvisionedKey];
        sort($expectedLines);
        $expectedB64 = base64_encode(implode("\n", $expectedLines)."\n");
        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server, $expectedB64) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys ('.$server->ssh_user.')'
                    && str_contains($script, $expectedB64)
                    && $timeout === 60
                    && $asRoot === false
                    && str_contains($script, 'DPLY_AUTH_EXIT');
            })
            ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys (root)'
                    && $timeout === 60
                    && $asRoot === false
                    && str_contains($script, "sudo -n -u 'root'");
            })
            ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));

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

    public function test_sync_retries_with_deploy_user_when_root_ssh_fails(): void
    {
        config()->set('server_ssh_keys.use_root_ssh', true);
        config()->set('server_ssh_keys.fallback_to_deploy_user_ssh', true);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '203.0.113.50',
            'ssh_user' => 'dply',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys (root)'
                    && is_string($script)
                    && $timeout === 60
                    && $asRoot === true;
            })
            ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys (dply)'
                    && is_string($script)
                    && $timeout === 60
                    && $asRoot === true;
            })
            ->andThrow(new \RuntimeException('root ssh failed'));
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys (dply)'
                    && is_string($script)
                    && $timeout === 60
                    && $asRoot === false;
            })
            ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));

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

    public function test_sync_preserves_hidden_root_recovery_key_separately_from_operational_login_key(): void
    {
        config()->set('server_ssh_keys.use_root_ssh', false);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $recoveryKey = RSA::createKey(2048)->toString('OpenSSH');
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '203.0.113.50',
            'ssh_user' => 'dply',
            'ssh_private_key' => $recoveryKey,
            'ssh_recovery_private_key' => $recoveryKey,
            'ssh_operational_private_key' => $this->validPrivateKey(),
        ]);

        $panelKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0';

        ServerAuthorizedKey::create([
            'server_id' => $server->id,
            'public_key' => $panelKey,
            'name' => 'test',
        ]);

        $expectedOperationalLines = [$panelKey, $server->openSshPublicKeyFromOperationalPrivate()];
        sort($expectedOperationalLines);
        $expectedOperationalB64 = base64_encode(implode("\n", $expectedOperationalLines)."\n");
        $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script) use ($server, $expectedOperationalB64) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys (dply)'
                    && str_contains($script, $expectedOperationalB64);
            })
            ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));
        $remote->shouldReceive('runScript')
            ->once()
            ->withArgs(function ($srv, $name, $script) use ($server) {
                return $srv->is($server)
                    && $name === 'Write authorized_keys (root)'
                    && str_contains($script, "sudo -n -u 'root'");
            })
            ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));

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
