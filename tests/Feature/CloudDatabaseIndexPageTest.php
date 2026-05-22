<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TeardownCloudDatabaseJob;
use App\Livewire\Cloud\DatabaseIndex as CloudDatabaseIndex;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class CloudDatabaseIndexPageTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.cloud'];

    public function test_page_renders_with_empty_state(): void
    {
        $user = $this->ownerWithOrg();

        $this->actingAs($user)->get(route('cloud.databases.index'))
            ->assertOk()
            ->assertSee('Managed databases')
            ->assertSee('No managed databases found');
    }

    public function test_page_is_gated_by_auth(): void
    {
        $this->get(route('cloud.databases.index'))->assertRedirect(route('login'));
    }

    public function test_page_is_gated_by_surface_cloud_feature(): void
    {
        \Laravel\Pennant\Feature::define('surface.cloud', fn () => false);
        \Laravel\Pennant\Feature::flushCache();
        $user = $this->ownerWithOrg();

        // The feature:surface.cloud route middleware aborts before the
        // component mounts — Pennant's EnsureFeaturesAreActive uses 400.
        $this->actingAs($user)->get(route('cloud.databases.index'))->assertStatus(400);
    }

    public function test_lists_only_databases_for_current_org(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $otherOrg = Organization::factory()->create();

        CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'mine-db']);
        CloudDatabase::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'other-org-db']);

        $this->actingAs($user)->get(route('cloud.databases.index'))
            ->assertOk()
            ->assertSee('mine-db')
            ->assertDontSee('other-org-db');
    }

    public function test_shows_engine_size_region_and_status(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        CloudDatabase::factory()->active()->create([
            'organization_id' => $org->id,
            'name' => 'live-pg',
            'engine' => CloudDatabase::ENGINE_POSTGRES,
            'size' => 'medium',
            'region' => 'ams3',
        ]);

        $this->actingAs($user)->get(route('cloud.databases.index'))
            ->assertSee('live-pg')
            ->assertSee('Postgres')
            ->assertSee('Medium')
            ->assertSee('ams3')
            ->assertSee('Active');
    }

    public function test_filter_by_engine(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'pg-one']);
        CloudDatabase::factory()->redis()->create(['organization_id' => $org->id, 'name' => 'redis-one']);

        Livewire::actingAs($user)
            ->test(CloudDatabaseIndex::class)
            ->set('engine', 'redis')
            ->assertSee('redis-one')
            ->assertDontSee('pg-one');
    }

    public function test_filter_by_status(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        CloudDatabase::factory()->active()->create(['organization_id' => $org->id, 'name' => 'healthy-db']);
        CloudDatabase::factory()->create([
            'organization_id' => $org->id,
            'name' => 'failed-db',
            'status' => CloudDatabase::STATUS_FAILED,
        ]);

        Livewire::actingAs($user)
            ->test(CloudDatabaseIndex::class)
            ->set('status', CloudDatabase::STATUS_FAILED)
            ->assertSee('failed-db')
            ->assertDontSee('healthy-db');
    }

    public function test_filter_counts_match_actual_databases(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
        CloudDatabase::factory()->create(['organization_id' => $org->id]);
        CloudDatabase::factory()->redis()->create([
            'organization_id' => $org->id,
            'status' => CloudDatabase::STATUS_FAILED,
        ]);

        Livewire::actingAs($user)
            ->test(CloudDatabaseIndex::class)
            ->assertViewHas('totals', fn ($t): bool => $t['all'] === 3
                && $t['postgres'] === 2
                && $t['redis'] === 1
                && $t['active'] === 1
                && $t['provisioning'] === 1
                && $t['failed'] === 1);
    }

    public function test_create_button_links_to_create_page(): void
    {
        $user = $this->ownerWithOrg();

        $this->actingAs($user)->get(route('cloud.databases.index'))
            ->assertSee(route('cloud.databases.create'));
    }

    public function test_tear_down_dispatches_job_and_marks_deleting(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(CloudDatabaseIndex::class)
            ->call('tearDown', $database->id);

        Queue::assertPushed(TeardownCloudDatabaseJob::class, fn (TeardownCloudDatabaseJob $job): bool => $job->cloudDatabaseId === $database->id);
        $this->assertSame(CloudDatabase::STATUS_DELETING, $database->fresh()->status);
    }

    public function test_tear_down_ignores_database_from_another_org(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
        $otherOrg = Organization::factory()->create();
        $database = CloudDatabase::factory()->active()->create(['organization_id' => $otherOrg->id]);

        Livewire::actingAs($user)
            ->test(CloudDatabaseIndex::class)
            ->call('tearDown', $database->id)
            ->assertDispatched('notify');

        Queue::assertNotPushed(TeardownCloudDatabaseJob::class);
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
