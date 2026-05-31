<?php

declare(strict_types=1);

namespace Tests\Feature\ServerCreateStepWhatPresetsTest;

use App\Livewire\Servers\Create\StepWhat;
use App\Models\Organization;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('apply preset for laravel pins php 84 mysql redis', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('applyPreset', 'laravel')
        ->assertSet('selectedPreset', 'laravel')
        ->assertSet('form.server_role', 'application')
        ->assertSet('form.webserver', 'nginx')
        ->assertSet('form.php_version', '8.4')
        ->assertSet('form.database', 'mysql84')
        ->assertSet('form.cache_service', 'redis')
        ->assertSet('form.install_profile', 'laravel_app');
});
test('apply preset for rails uses postgres and application role', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('applyPreset', 'rails')
        ->assertSet('form.server_role', 'application')
        ->assertSet('form.webserver', 'nginx')
        ->assertSet('form.database', 'postgres17')
        ->assertSet('form.cache_service', 'redis')
        ->assertSet('form.ruby_version', '3.3')
        // Rails has no PHP — applying the preset clears any prior pin
        // back to "none" so review screen reflects the actual stack.
        ->assertSet('form.php_version', 'none')
        ->assertSet('form.node_version', '')
        ->assertSet('form.python_version', '')
        ->assertSet('form.go_version', '');
});
test('apply preset for polyglot fills every language runtime', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('applyPreset', 'polyglot')
        ->assertSet('form.ruby_version', '3.3')
        ->assertSet('form.node_version', '22')
        ->assertSet('form.python_version', '3.12')
        ->assertSet('form.go_version', '1.22')
        ->assertSet('form.php_version', '8.4');
});
test('switching from rails to laravel clears ruby pin', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('applyPreset', 'rails')
        ->assertSet('form.ruby_version', '3.3')
        ->call('applyPreset', 'laravel')
        ->assertSet('form.ruby_version', '')
        ->assertSet('form.php_version', '8.4');
});
test('apply preset for polyglot keeps php pinned', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('applyPreset', 'polyglot')
        ->assertSet('form.php_version', '8.4')
        ->assertSet('form.database', 'postgres17')
        ->assertSet('form.cache_service', 'redis');
});
test('apply preset for static clears selection to static role', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('applyPreset', 'static')
        ->assertSet('form.server_role', 'static')
        ->assertSet('form.install_profile', 'static_app_host');
});
test('apply preset for custom marks selection without changing form', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('form.server_role', 'application')
        ->set('form.php_version', '8.3')
        ->call('applyPreset', 'custom')
        ->assertSet('selectedPreset', 'custom')
        ->assertSet('form.server_role', 'application')
        ->assertSet('form.php_version', '8.3');
});
test('apply preset for unknown id is a no op', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('form.server_role', 'application')
        ->call('applyPreset', 'made-up')
        ->assertSet('selectedPreset', '')
        ->assertSet('form.server_role', 'application');
});
test('step what view renders featured preset tiles', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSee('Polyglot host')
        ->assertSee('Laravel app')
        ->assertSee('Rails app')
        ->assertSee('Next.js / Node API')
        ->assertSee('Django / FastAPI');
});

test('step what hides app templates for dedicated redis server purpose', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    ServerCreateDraft::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 3,
        'payload' => [
            'mode' => 'provider',
            'type' => 'hetzner',
            'name' => 'redis-host',
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
            'webserver' => 'none',
            'php_version' => 'none',
            'database' => 'none',
            'cache_service' => 'redis',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSee('What we\'ll install')
        ->assertSee('Cache engine')
        ->assertSee('Cache / key-value server')
        ->assertSee('Redis')
        ->assertSee('Network access')
        ->assertSee('Localhost only')
        ->assertSee('Other servers on my network')
        ->assertSee('Provision preview')
        ->assertSee('Valkey')
        ->assertSee('Soon')
        ->assertDontSee('Pick a stack template')
        ->assertDontSee('Polyglot host');
});

test('dedicated cache wizard supports engine and network access pickers', function () {
    config(['features.cache.valkey' => true]);
    Feature::flushCache();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    ServerCreateDraft::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 3,
        'payload' => [
            'mode' => 'provider',
            'type' => 'hetzner',
            'name' => 'cache-host',
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
            'webserver' => 'none',
            'php_version' => 'none',
            'database' => 'none',
            'cache_service' => 'redis',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->call('chooseDedicatedCacheEngine', 'valkey')
        ->assertSet('form.cache_service', 'valkey')
        ->call('chooseCacheNetworkAccess', 'remote')
        ->assertSet('form.cache_remote_access', true)
        ->call('chooseCacheAuthMode', 'password')
        ->assertSet('form.cache_require_password', true)
        ->assertNotSet('form.cache_password', '');
});

test('changing an override keeps the overrides panel open', function () {
    $user = seedUserWithDraft();

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('overridesPanelOpen', true)
        ->set('form.install_profile', 'queue_worker')
        ->assertSet('overridesPanelOpen', true);
});
function seedUserWithDraft(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    ServerCreateDraft::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 3,
        'payload' => [
            'mode' => 'provider',
            'type' => 'digitalocean',
            'name' => 'test',
            'install_profile' => 'laravel_app',
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    return $user;
}
