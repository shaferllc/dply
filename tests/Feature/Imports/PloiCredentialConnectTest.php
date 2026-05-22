<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\PloiCredentialConnectTest;
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
test('store ploi validates required fields', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('ploi_api_token', '')
        ->call('storePloi')
        ->assertHasErrors('ploi_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});
test('store ploi persists when token valid', function () {
    Http::fake([
        'https://ploi.io/api/user' => Http::response(['data' => ['id' => 1, 'email' => 'x@y.z']], 200),
    ]);

    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('ploi_name', 'My Ploi')
        ->set('ploi_api_token', 'ploi_valid_token')
        ->call('storePloi')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'provider' => 'ploi',
        'name' => 'My Ploi',
        'user_id' => $user->id,
    ]);
});
test('store ploi rejects invalid token and does not persist', function () {
    Http::fake([
        'https://ploi.io/api/user' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('ploi_api_token', 'ploi_bad_token')
        ->call('storePloi')
        ->assertHasErrors('ploi_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});
test('verify credential rechecks token', function () {
    Http::fake([
        'https://ploi.io/api/user' => Http::response(['data' => ['id' => 1]], 200),
    ]);

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_valid_token'],
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->call('verifyCredential', $credential->id)
        ->assertHasNoErrors();
});
