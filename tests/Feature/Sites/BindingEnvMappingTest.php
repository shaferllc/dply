<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\BindingEnvMappingTest;

use App\Livewire\Sites\SiteEnvironment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site, 3: SiteBinding}
 */
function mappingFixture(string $envFile = 'APP_NAME=dply'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'env_file_content' => $envFile,
    ]);
    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'injected_env' => [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'databio',
            'DB_USERNAME' => 'databio_r0ld',
            'DB_PASSWORD' => 'sekret',
            'DATABASE_URL' => 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio',
        ],
        'config' => ['engine' => 'postgres', 'connection' => ''],
    ]);

    return [$user, $server, $site, $binding];
}

test('saving an alias makes the binding inject it under the new name', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->assertSet('envMappingPending', false)
        ->set('envMappingAliases.DATABASE_URL', 'DATABASE_URI, POSTGRES_URL')
        ->call('saveEnvMapping')
        ->assertHasNoErrors();

    $env = $binding->fresh()->connectionEnv();

    expect($env)
        ->toHaveKey('DATABASE_URI', 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio')
        ->toHaveKey('POSTGRES_URL', 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio')
        // The canonical name always survives — aliases add, never replace.
        ->toHaveKey('DATABASE_URL', 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio');
});

test('an alias colliding with the resource own key is rejected, not silently dropped', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingAliases.DB_PORT', 'DB_HOST')
        ->call('saveEnvMapping')
        ->assertHasErrors('envMappingAliases.DB_PORT');

    expect($binding->fresh()->envAliases())->toBe([]);
});

test('an alias colliding with a site env variable is rejected', function () {
    [$user, $server, $site, $binding] = mappingFixture("APP_NAME=dply\nPGHOST=elsewhere");

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingAliases.DB_HOST', 'PGHOST')
        ->call('saveEnvMapping')
        ->assertHasErrors('envMappingAliases.DB_HOST');
});

test('an alias colliding with another binding is rejected', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'injected_env' => ['REDIS_HOST' => '127.0.0.1'],
        'config' => ['engine' => 'redis'],
    ]);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingAliases.DB_HOST', 'REDIS_HOST')
        ->call('saveEnvMapping')
        ->assertHasErrors('envMappingAliases.DB_HOST');
});

test('an invalid variable name is rejected', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingAliases.DB_HOST', '9-not-a-key')
        ->call('saveEnvMapping')
        ->assertHasErrors('envMappingAliases.DB_HOST');
});

test('the same alias name cannot be used twice in one save', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingAliases.DB_HOST', 'PGTARGET')
        ->set('envMappingAliases.DB_PORT', 'PGTARGET')
        ->call('saveEnvMapping')
        ->assertHasErrors('envMappingAliases.DB_PORT');
});

test('an override replaces the injected value and stamps redeploy-to-apply', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    expect(data_get($binding->config, 'connection_ready_at'))->toBeNull();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingOverrides.DB_HOST', '10.0.0.7')
        ->call('saveEnvMapping')
        ->assertHasNoErrors();

    $fresh = $binding->fresh();

    expect($fresh->connectionEnv())->toHaveKey('DB_HOST', '10.0.0.7')
        ->and(data_get($fresh->config, 'connection_ready_at'))->not->toBeNull();
});

test('clearing every alias records an empty map so detection cannot re-seed it', function () {
    [$user, $server, $site, $binding] = mappingFixture();
    $binding->forceFill(['env_customization' => ['aliases' => ['DATABASE_URL' => ['DATABASE_URI']]]])->save();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->assertSet('envMappingAliases.DATABASE_URL', 'DATABASE_URI')
        ->set('envMappingAliases.DATABASE_URL', '')
        ->call('saveEnvMapping')
        ->assertHasNoErrors();

    $fresh = $binding->fresh();

    expect($fresh->envAliases())->toBe([])
        ->and($fresh->hasEnvAliasMap())->toBeTrue()
        ->and($fresh->connectionEnv())->not->toHaveKey('DATABASE_URI');
});

test('someone outside the organization cannot reach the mapping editor', function () {
    [, $server, $site] = mappingFixture();

    $outsider = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    $otherOrg->users()->attach($outsider->id, ['role' => 'owner']);
    session(['current_organization_id' => $otherOrg->id]);

    // The page itself is gated, so openEnvMapping/saveEnvMapping are never
    // reachable; their own authorize('update') calls are defence in depth.
    Livewire::actingAs($outsider)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->assertForbidden();
});

test('a mapping only ever applies to the binding it was saved on', function () {
    [$user, $server, $site, $binding] = mappingFixture();

    $other = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'injected_env' => ['REDIS_HOST' => '127.0.0.1'],
        'config' => ['engine' => 'redis'],
    ]);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('openEnvMapping', $binding->id)
        ->set('envMappingAliases.DB_HOST', 'PGHOST')
        ->call('saveEnvMapping')
        ->assertHasNoErrors();

    expect($binding->fresh()->connectionEnv())->toHaveKey('PGHOST')
        ->and($other->fresh()->connectionEnv())->not->toHaveKey('PGHOST');
});
