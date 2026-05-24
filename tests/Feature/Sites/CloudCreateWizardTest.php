<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CloudCreateWizardTest;

use App\Livewire\Cloud\Create as CloudCreate;
use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization}
 */
function bootCloudOrg(): array
{
    Feature::define('surface.cloud', fn (): bool => true);
    Feature::flushCache();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return [$user, $org];
}

test('wizard renders and shows the workers + database + autoscaling + health cards', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->assertSee('Background workers')
        ->assertSee('Database')
        ->assertSee('CPU-target autoscaling')
        ->assertSee('HTTP health check');
});

test('addWorker appends with defaults', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->call('addWorker', 'worker')
        ->call('addWorker', 'scheduler')
        ->assertCount('workers', 2)
        ->assertSet('workers.0.type', 'worker')
        ->assertSet('workers.0.command', CloudWorker::DEFAULT_WORKER_COMMAND)
        ->assertSet('workers.1.type', 'scheduler')
        ->assertSet('workers.1.command', CloudWorker::SCHEDULER_COMMAND);
});

test('addWorker rejects second scheduler', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->call('addWorker', 'scheduler')
        ->call('addWorker', 'scheduler')
        ->assertCount('workers', 1);
});

test('deploy passes workers + autoscaling + health + database into the action', function () {
    Bus::fake();
    [$user, $org] = bootCloudOrg();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id, 'name' => 'main']);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'image')
        ->set('name', 'my-app')
        ->set('image', 'ghcr.io/acme/api:v1')
        ->set('backend', 'digitalocean_app_platform')
        ->set('region', 'nyc')
        ->call('addWorker', 'worker')
        ->call('addWorker', 'scheduler')
        ->set('workers.0.command', 'php artisan queue:work redis')
        ->set('workers.0.instance_count', 3)
        ->set('autoscaling_enabled', true)
        ->set('autoscaling_min', 2)
        ->set('autoscaling_max', 6)
        ->set('autoscaling_cpu_percent', 65)
        ->set('health_check_enabled', true)
        ->set('health_check_path', '/up')
        ->set('database_mode', 'attach')
        ->set('database_id', $db->id)
        ->call('deploy')
        ->assertHasNoErrors();

    $site = Site::query()->where('name', 'my-app')->first();
    expect($site)->not->toBeNull();

    // Workers created before provision dispatch:
    $workers = CloudWorker::query()->where('site_id', $site->id)->get();
    expect($workers)->toHaveCount(2);
    expect($workers->firstWhere('type', 'worker')->command)->toBe('php artisan queue:work redis');
    expect($workers->firstWhere('type', 'worker')->instance_count)->toBe(3);
    expect($workers->firstWhere('type', 'scheduler'))->not->toBeNull();

    // Autoscaling + health-check persisted into meta:
    expect($site->meta['container']['autoscaling']['enabled'] ?? null)->toBeTrue();
    expect($site->meta['container']['autoscaling']['min_instances'])->toBe(2);
    expect($site->meta['container']['autoscaling']['max_instances'])->toBe(6);
    expect($site->meta['container']['health_check']['enabled'] ?? null)->toBeTrue();
    expect($site->meta['container']['health_check']['http_path'])->toBe('/up');

    // Database pivot + env vars wired:
    expect($db->sites()->where('sites.id', $site->id)->exists())->toBeTrue();
    expect($site->env_file_content)->toContain('DB_CONNECTION=');
});

test('autoscaling validation rejects max < min', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'image')
        ->set('name', 'my-app')
        ->set('image', 'x:1')
        ->set('backend', 'digitalocean_app_platform')
        ->set('region', 'nyc')
        ->set('autoscaling_enabled', true)
        ->set('autoscaling_min', 5)
        ->set('autoscaling_max', 2)
        ->call('deploy')
        ->assertHasErrors(['autoscaling_max']);
});

test('health check validation requires leading slash', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'image')
        ->set('name', 'my-app')
        ->set('image', 'x:1')
        ->set('backend', 'digitalocean_app_platform')
        ->set('region', 'nyc')
        ->set('health_check_enabled', true)
        ->set('health_check_path', 'no-slash')
        ->call('deploy')
        ->assertHasErrors(['health_check_path']);
});

test('attach database requires a selection', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'image')
        ->set('name', 'my-app')
        ->set('image', 'x:1')
        ->set('backend', 'digitalocean_app_platform')
        ->set('region', 'nyc')
        ->set('database_mode', 'attach')
        ->set('database_id', '')
        ->call('deploy')
        ->assertHasErrors(['database_id']);
});

test('removeWorker re-indexes', function () {
    [$user] = bootCloudOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->call('addWorker', 'worker')
        ->call('addWorker', 'worker')
        ->call('addWorker', 'worker')
        ->set('workers.1.name', 'middle')
        ->call('removeWorker', 1)
        ->assertCount('workers', 2);
});
