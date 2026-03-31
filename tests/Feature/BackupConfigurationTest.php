<?php

namespace Tests\Feature;

use App\Livewire\Settings\BackupConfigurations;
use App\Models\BackupConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_backup_configurations(): void
    {
        $this->get(route('profile.backup-configurations'))
            ->assertRedirect();
    }

    public function test_authenticated_user_can_view_backup_configurations_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.backup-configurations'))
            ->assertOk()
            ->assertSee('Backup configurations', false);
    }

    public function test_user_can_create_custom_s3_backup_configuration(): void
    {
        $user = User::factory()->create();

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
            'user_id' => $user->id,
            'name' => 'Staging bucket',
            'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
        ]);

        $row = BackupConfiguration::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('AKIAEXAMPLE', $row->config['access_key']);
        $this->assertSame('secret-value', $row->config['secret']);
        $this->assertTrue($row->config['use_path_style']);
    }

    public function test_search_filters_configurations_by_name(): void
    {
        $user = User::factory()->create();
        BackupConfiguration::factory()->forUser($user)->create(['name' => 'Alpha backups']);
        BackupConfiguration::factory()->forUser($user)->create(['name' => 'Beta archive']);

        Livewire::actingAs($user)
            ->test(BackupConfigurations::class)
            ->set('search', 'Beta')
            ->assertSee('Beta archive', false)
            ->assertDontSee('Alpha backups', false);
    }

    public function test_user_cannot_delete_another_users_configuration(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $config = BackupConfiguration::factory()->forUser($owner)->create();

        Livewire::actingAs($other)
            ->test(BackupConfigurations::class)
            ->call('deleteConfiguration', $config->id)
            ->assertForbidden();

        $this->assertDatabaseHas('backup_configurations', ['id' => $config->id]);
    }
}
