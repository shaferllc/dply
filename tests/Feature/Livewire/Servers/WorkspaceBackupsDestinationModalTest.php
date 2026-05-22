<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers\WorkspaceBackupsDestinationModalTest;

use App\Livewire\Servers\WorkspaceBackups;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{User, Organization, Server} */
function ownerWithServer(): array
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
test('modal opens and clears state', function () {
    [$user, , $server] = ownerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('openDestinationModal')
        ->assertSet('showDestinationModal', true)
        ->assertSet('destinationForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3);
});
test('save creates org scoped row and autoselects it', function () {
    [$user, $org, $server] = ownerWithServer();

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

    expect($row)->not->toBeNull('destination row was not created on the server organization');
    expect($row->created_by_user_id)->toBe($user->id);

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
});
test('local provider is rejected in modal too', function () {
    [$user, , $server] = ownerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('openDestinationModal')
        ->set('destinationForm.name', 'Ignored')
        ->set('destinationForm.provider', 'local')
        ->call('saveDestination')
        ->assertHasErrors(['destinationForm.provider']);

    $this->assertDatabaseCount('backup_configurations', 0);
});
test('destination dropdown lists org destinations', function () {
    [$user, $org, $server] = ownerWithServer();
    BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Org shared S3']);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->assertSee('Org shared S3', false);
});
