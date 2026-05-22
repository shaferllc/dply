<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\ForgeCredentialConnectTest;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
test('store forge validates required fields', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('forge_api_token', '')
        ->call('storeForge')
        ->assertHasErrors('forge_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});
test('store forge persists when token valid', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers' => Http::response(['servers' => []], 200),
    ]);

    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('forge_name', 'Agency Forge')
        ->set('forge_api_token', 'forge_valid_token')
        ->call('storeForge')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'provider' => 'forge',
        'name' => 'Agency Forge',
        'user_id' => $user->id,
    ]);
});
test('store forge rejects invalid token and does not persist', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('forge_api_token', 'forge_bad_token')
        ->call('storeForge')
        ->assertHasErrors('forge_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});
test('verify credential rechecks token', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers' => Http::response(['servers' => []], 200),
    ]);

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'forge',
        'credentials' => ['api_token' => 'forge_valid_token'],
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->call('verifyCredential', $credential->id)
        ->assertHasNoErrors();
});
test('credentials sidebar includes forge tab', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', ['organization' => $org, 'provider' => 'forge']));

    $response->assertOk()
        ->assertSee('Migrate sites from Laravel Forge to dply')
        ->assertSee('Connect Laravel Forge')
        ->assertSee('Migrate from');
});
