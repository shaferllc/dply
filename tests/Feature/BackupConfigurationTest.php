<?php

namespace Tests\Feature;

use App\Livewire\Settings\BackupConfigurations;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a user with a single organization membership. Single org means
     * User::currentOrganization() returns it without needing a session set.
     */
    private function userInNewOrg(?Organization $org = null): array
    {
        $user = User::factory()->create();
        $org ??= Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    public function test_guest_cannot_view_backup_configurations(): void
    {
        $this->get(route('profile.backup-configurations'))
            ->assertRedirect();
    }

    public function test_authenticated_user_can_view_backup_destinations_page(): void
    {
        [$user] = $this->userInNewOrg();

        $this->actingAs($user)
            ->get(route('profile.backup-configurations'))
            ->assertOk()
            ->assertSee('Organization backup destinations', false);
    }

    public function test_user_can_create_custom_s3_backup_destination_under_their_org(): void
    {
        [$user, $org] = $this->userInNewOrg();

        Livewire::actingAs($user)
            ->test(BackupConfigurations::class)
            ->set('createForm.name', 'Staging bucket')
            ->set('createForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3)
            ->set('createForm.s3.access_key', 'AKIAEXAMPLE')
            ->set('createForm.s3.secret', 'secret-value')
            ->set('createForm.s3.bucket', 'my-bucket')
            ->set('createForm.s3.region', 'nl-ams1')
            ->set('createForm.s3.endpoint', 'https://s3.example.com')
            ->set('createForm.s3.use_path_style', true)
            ->call('createConfiguration')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('backup_configurations', [
            'organization_id' => $org->id,
            'created_by_user_id' => $user->id,
            'name' => 'Staging bucket',
            'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
        ]);

        $row = BackupConfiguration::query()->where('organization_id', $org->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('AKIAEXAMPLE', $row->config['access_key']);
        $this->assertSame('secret-value', $row->config['secret']);
        $this->assertTrue($row->config['use_path_style']);
    }

    public function test_local_provider_is_no_longer_accepted_by_the_form(): void
    {
        [$user] = $this->userInNewOrg();

        Livewire::actingAs($user)
            ->test(BackupConfigurations::class)
            ->set('createForm.name', 'Should be rejected')
            ->set('createForm.provider', 'local')
            ->call('createConfiguration')
            ->assertHasErrors(['createForm.provider']);

        $this->assertDatabaseCount('backup_configurations', 0);
    }

    public function test_search_filters_destinations_by_name(): void
    {
        [$user, $org] = $this->userInNewOrg();
        BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Alpha backups']);
        BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Beta archive']);

        Livewire::actingAs($user)
            ->test(BackupConfigurations::class)
            ->set('search', 'Beta')
            ->assertSee('Beta archive', false)
            ->assertDontSee('Alpha backups', false);
    }

    public function test_teammates_can_view_and_edit_each_others_destinations(): void
    {
        $org = Organization::factory()->create();
        [$alice] = $this->userInNewOrg($org);
        [$bob] = $this->userInNewOrg($org);

        $config = BackupConfiguration::factory()
            ->forOrganization($org)
            ->createdBy($alice)
            ->create(['name' => 'Shared bucket']);

        // Bob sees what Alice created.
        Livewire::actingAs($bob)
            ->test(BackupConfigurations::class)
            ->assertSee('Shared bucket', false);

        // Bob can rename it — destinations are org-shared, not creator-owned.
        Livewire::actingAs($bob)
            ->test(BackupConfigurations::class)
            ->call('startEdit', $config->id)
            ->set('editForm.name', 'Renamed by Bob')
            ->call('updateConfiguration')
            ->assertHasNoErrors();

        $this->assertSame('Renamed by Bob', $config->fresh()->name);
    }

    public function test_user_in_different_org_cannot_delete_destination(): void
    {
        [, $ownerOrg] = $this->userInNewOrg();
        $config = BackupConfiguration::factory()->forOrganization($ownerOrg)->create();

        [$outsider] = $this->userInNewOrg(); // membership in a brand-new org

        Livewire::actingAs($outsider)
            ->test(BackupConfigurations::class)
            ->call('deleteConfiguration', $config->id)
            ->assertForbidden();

        $this->assertDatabaseHas('backup_configurations', ['id' => $config->id]);
    }
}
