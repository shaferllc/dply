<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\OnboardingEmptyStateTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
test('empty state shows migrate cta when ploi credential connected', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);

    $this->actingAs($user)
        ->get('/servers')
        ->assertOk()
        ->assertSee('Migrate from Ploi');
});
test('empty state omits migrate cta when no import credential', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    // Only a compute credential — should NOT trigger the migrate CTA.
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    $this->actingAs($user)
        ->get('/servers')
        ->assertOk()
        ->assertDontSee('Migrate from Ploi');
});
test('empty state shows forge cta when only forge credential present', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'forge',
    ]);

    $this->actingAs($user)
        ->get('/servers')
        ->assertOk()
        ->assertSee('Migrate from Forge')
        ->assertDontSee('Migrate from Ploi');
});
test('empty state shows both ctas when both credentials present', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'forge',
    ]);

    $this->actingAs($user)
        ->get('/servers')
        ->assertOk()
        ->assertSee('Migrate from Ploi')
        ->assertSee('Migrate from Forge');
});
