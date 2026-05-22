<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers;

use App\Livewire\Servers\WorkspaceBackups;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the "Add destination" modal on the server-workspace Backups page —
 * the inline shortcut introduced so operators don't have to round-trip to
 * /settings/backup-configurations just to register a new bucket.
 */
class WorkspaceBackupsDestinationModalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Organization, Server} */
    private function ownerWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return [$user, $org, $server];
    }

    public function test_modal_opens_and_clears_state(): void
    {
        [$user, , $server] = $this->ownerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('openDestinationModal')
            ->assertSet('showDestinationModal', true)
            ->assertSet('destinationForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3);
    }

    public function test_save_creates_org_scoped_row_and_autoselects_it(): void
    {
        [$user, $org, $server] = $this->ownerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('openDestinationModal')
            ->set('destinationForm.name', 'From modal S3')
            ->set('destinationForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3)
            ->set('destinationForm.s3.access_key', 'AKIA')
            ->set('destinationForm.s3.secret', 'shh')
            ->set('destinationForm.s3.bucket', 'my-bucket')
            ->set('destinationForm.s3.endpoint', 'https://s3.example.com')
            ->call('saveDestination')
            ->assertHasNoErrors()
            ->assertSet('showDestinationModal', false);

        $row = BackupConfiguration::query()
            ->where('organization_id', $org->id)
            ->where('name', 'From modal S3')
            ->first();

        $this->assertNotNull($row, 'destination row was not created on the server organization');
        $this->assertSame($user->id, $row->created_by_user_id);

        // The new row is auto-selected on the schedule form so the operator
        // can submit the schedule without a second pointer-trip.
        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('openDestinationModal')
            ->set('destinationForm.name', 'Another')
            ->set('destinationForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3)
            ->set('destinationForm.s3.access_key', 'AKIA')
            ->set('destinationForm.s3.secret', 'shh')
            ->set('destinationForm.s3.bucket', 'another-bucket')
            ->set('destinationForm.s3.endpoint', 'https://s3.example.com')
            ->call('saveDestination')
            ->assertHasNoErrors()
            ->assertSet('new_backup_configuration_id', BackupConfiguration::query()
                ->where('name', 'Another')->value('id'));
    }

    public function test_local_provider_is_rejected_in_modal_too(): void
    {
        [$user, , $server] = $this->ownerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('openDestinationModal')
            ->set('destinationForm.name', 'Ignored')
            ->set('destinationForm.provider', 'local')
            ->call('saveDestination')
            ->assertHasErrors(['destinationForm.provider']);

        $this->assertDatabaseCount('backup_configurations', 0);
    }

    public function test_destination_dropdown_lists_org_destinations(): void
    {
        [$user, $org, $server] = $this->ownerWithServer();
        BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Org shared S3']);

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->assertSee('Org shared S3', false);
    }
}
