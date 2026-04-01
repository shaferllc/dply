<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerSshAccessRepairer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceSettingsRepairAccessTest extends TestCase
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
            'ssh_user' => 'dply',
            'ip_address' => '203.0.113.10',
            'ssh_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
            'ssh_recovery_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
            'ssh_operational_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
        ]);

        return [$user, $server];
    }

    public function test_connection_settings_can_repair_ssh_access(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerSshAccessRepairer::class, function ($mock) use ($server) {
            $mock->shouldReceive('repairOperationalAccess')
                ->once()
                ->withArgs(fn (Server $passedServer): bool => $passedServer->is($server))
                ->andReturn("repair ok\n");
        });

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
            ->call('repairSshAccess')
            ->assertSet('flash_success', 'SSH access repaired. Dply reinstalled the operational key for this server.');
    }
}
