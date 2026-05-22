<?php

declare(strict_types=1);

namespace Tests\Feature\ContainerProviderCredentialsTest;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('panels visible only when provider is enabled', function () {
    config([
        'server_providers.enabled.digitalocean_app_platform' => false,
        'server_providers.enabled.aws_app_runner' => false,
    ]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

    $response->assertOk()
        ->assertDontSee('DigitalOcean App Platform')
        ->assertDontSee('AWS App Runner');
});
test('do app platform panel renders value prop', function () {
    config(['server_providers.enabled.digitalocean_app_platform' => true]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', [
        'organization' => $org,
        'provider' => 'digitalocean_app_platform',
    ]));

    $response->assertOk()
        ->assertSee('Container backend')
        ->assertSee('DigitalOcean App Platform');
});
test('aws app runner panel renders value prop', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', [
        'organization' => $org,
        'provider' => 'aws_app_runner',
    ]));

    $response->assertOk()
        ->assertSee('Container backend')
        ->assertSee('App Runner');
});
test('store do app platform credential', function () {
    config(['server_providers.enabled.digitalocean_app_platform' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('active_provider', 'digitalocean_app_platform')
        ->set('do_app_platform_name', 'Production')
        ->set('do_app_platform_api_token', 'dop_v1_abcdef')
        ->call('storeDigitalOceanAppPlatform');

    $this->assertDatabaseHas('provider_credentials', [
        'user_id' => $user->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'Production',
    ]);
});
test('store aws app runner credential', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('active_provider', 'aws_app_runner')
        ->set('aws_app_runner_name', 'AppRunner US')
        ->set('aws_app_runner_access_key_id', 'AKIA1234567890')
        ->set('aws_app_runner_secret_access_key', 'verysecret')
        ->set('aws_app_runner_region', 'us-west-2')
        ->call('storeAwsAppRunner');

    $cred = ProviderCredential::query()
        ->where('user_id', $user->id)
        ->where('provider', 'aws_app_runner')
        ->first();
    expect($cred)->not->toBeNull();
    expect($cred->name)->toBe('AppRunner US');
    expect($cred->credentials['access_key_id'])->toBe('AKIA1234567890');
    expect($cred->credentials['region'])->toBe('us-west-2');
});
test('aws app runner validates required fields', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('active_provider', 'aws_app_runner')
        ->call('storeAwsAppRunner')
        ->assertHasErrors(['aws_app_runner_access_key_id', 'aws_app_runner_secret_access_key']);

    $this->assertDatabaseCount('provider_credentials', 0);
});
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
