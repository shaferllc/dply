<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionCloudDatabaseJob;
use App\Livewire\Cloud\DatabaseCreate as CloudDatabaseCreate;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class CloudDatabaseCreatePageTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.cloud'];

    public function test_page_renders(): void
    {
        $user = $this->ownerWithOrg();

        $this->actingAs($user)->get(route('cloud.databases.create'))
            ->assertOk()
            ->assertSee('Create a managed database')
            ->assertSee('Engine')
            ->assertSee('Region')
            ->assertSee('Size');
    }

    public function test_page_is_gated_by_auth(): void
    {
        $this->get(route('cloud.databases.create'))->assertRedirect(route('login'));
    }

    public function test_page_is_gated_by_surface_cloud_feature(): void
    {
        \Laravel\Pennant\Feature::define('surface.cloud', fn () => false);
        \Laravel\Pennant\Feature::flushCache();
        $user = $this->ownerWithOrg();

        // The feature:surface.cloud route middleware aborts before the
        // component mounts — Pennant's EnsureFeaturesAreActive uses 400.
        $this->actingAs($user)->get(route('cloud.databases.create'))->assertStatus(400);
    }

    public function test_page_warns_when_no_digitalocean_credential(): void
    {
        $user = $this->ownerWithOrg();

        $this->actingAs($user)->get(route('cloud.databases.create'))
            ->assertSee('No DigitalOcean credential connected');
    }

    public function test_page_hides_warning_when_credential_connected(): void
    {
        $user = $this->ownerWithOrg();
        $this->connectDoCredential($user);

        $this->actingAs($user)->get(route('cloud.databases.create'))
            ->assertDontSee('No DigitalOcean credential connected');
    }

    public function test_create_validates_required_fields(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(CloudDatabaseCreate::class)
            ->set('name', '')
            ->call('create')
            ->assertHasErrors(['name']);
    }

    public function test_changing_engine_resets_version_to_newest_supported(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(CloudDatabaseCreate::class)
            ->set('engine', 'redis')
            ->assertSet('version', '7')
            ->set('engine', 'mysql')
            ->assertSet('version', '8');
    }

    public function test_create_dispatches_provision_job_and_redirects(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
        $this->connectDoCredential($user);

        Livewire::actingAs($user)
            ->test(CloudDatabaseCreate::class)
            ->set('name', 'acme-primary')
            ->set('engine', 'postgres')
            ->set('version', '16')
            ->set('size', 'small')
            ->set('region', 'nyc1')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect(route('cloud.databases.index'));

        Queue::assertPushed(ProvisionCloudDatabaseJob::class);

        $database = CloudDatabase::query()->where('name', 'acme-primary')->firstOrFail();
        $this->assertSame('postgres', $database->engine);
        $this->assertSame('16', $database->version);
        $this->assertSame('small', $database->size);
        $this->assertSame('nyc1', $database->region);
        $this->assertSame(CloudDatabase::STATUS_PROVISIONING, $database->status);
        $this->assertSame($user->currentOrganization()->id, $database->organization_id);
    }

    public function test_create_without_credential_shows_toast_error(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(CloudDatabaseCreate::class)
            ->set('name', 'lonely-db')
            ->set('engine', 'postgres')
            ->set('version', '16')
            ->set('size', 'small')
            ->set('region', 'nyc1')
            ->call('create')
            ->assertDispatched('notify');

        Queue::assertNotPushed(ProvisionCloudDatabaseJob::class);
        $this->assertDatabaseMissing('cloud_databases', ['name' => 'lonely-db']);
    }

    private function connectDoCredential(User $user): void
    {
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
    }

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
