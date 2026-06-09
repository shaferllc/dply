<?php

declare(strict_types=1);

namespace Tests\Feature\ContainerProviderCredentialsTest;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

usesFeatures('provider.aws_app_runner');

test('aws app runner panel hidden when provider disabled', function () {
    config(['server_providers.enabled.aws_app_runner' => false]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

    $response->assertOk()->assertDontSee('AWS App Runner');
});
test('digitalocean credential panel surfaces app platform support', function () {
    config(['server_providers.enabled.digitalocean' => true]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', [
        'organization' => $org,
        'provider' => 'digitalocean',
    ]));

    $response->assertOk()->assertSee('App Platform');
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
