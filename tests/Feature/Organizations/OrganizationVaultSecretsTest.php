<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationVaultSecretsTest;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\User;
use App\Modules\Secrets\Livewire\Secrets as OrganizationsSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can create a write-never secret', function () {
    [$user, $org] = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'sk_never_echo_this')
        ->set('vault_notes', 'production')
        ->call('createVaultSecret')
        ->assertHasNoErrors()
        ->assertSet('vault_value', '')
        ->assertDontSee('sk_never_echo_this')
        ->assertSee('STRIPE_SECRET')
        ->assertSee('production');

    $secret = OrganizationSecret::query()->where('organization_id', $org->id)->first();
    expect($secret)->not->toBeNull()
        ->and($secret->key)->toBe('STRIPE_SECRET')
        ->and($secret->value)->toBe('sk_never_echo_this');
});

test('duplicate key requires notes', function () {
    [$user, $org] = ownerWithOrg();
    OrganizationSecret::factory()->create([
        'organization_id' => $org->id,
        'key' => 'STRIPE_SECRET',
        'notes' => null,
    ]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'another')
        ->set('vault_notes', '')
        ->call('createVaultSecret')
        ->assertHasErrors(['vault_notes']);
});

test('member cannot create a secret', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'x')
        ->call('createVaultSecret')
        ->assertForbidden();
});

/**
 * @return array{0: User, 1: Organization}
 */
function ownerWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
