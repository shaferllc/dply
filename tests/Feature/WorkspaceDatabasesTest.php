<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceDatabases;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\User;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceDatabasesTest extends TestCase
{
    use RefreshDatabase;

    protected function actingOwnerWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        return [$user, $server];
    }

    public function test_create_database_with_optional_credentials_calls_provisioner(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => true, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldReceive('createOnServer')->once()->andReturn('mysql ok');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('new_db_name', 'myapp_db')
            ->set('new_db_engine', 'mysql')
            ->set('new_db_username', '')
            ->set('new_db_password', '')
            ->set('new_db_description', 'Staging')
            ->call('createDatabase')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_databases', [
            'server_id' => $server->id,
            'name' => 'myapp_db',
            'description' => 'Staging',
        ]);
    }

    public function test_delete_database_removes_record(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'x',
            'engine' => 'mysql',
            'username' => 'u',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('deleteDatabase', $db->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('server_databases', ['id' => $db->id]);
    }
}
