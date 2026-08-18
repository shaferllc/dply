<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationResidencyKeyTest;

use App\Models\Organization;
use App\Models\OrgSecretKey;
use App\Models\User;
use App\Modules\Secrets\Livewire\Secrets as OrganizationsSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('customer-held residency key can be reverted to dply-managed', function () {
    [$user, $org] = residencyOwnerWithOrg();
    seedCustomerHeldKey($org, 'age1existingcustomerkey');

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('tab', 'residency')
        ->assertSee('Rotate key')
        ->assertSee('Revert to dply-managed')
        ->call('confirmRevertToDplyHeld')
        ->assertSet('confirmActionModalMethod', 'revertToDplyHeldKey')
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal')
        ->assertSet('showConfirmActionModal', false);

    $key = $org->secretKey()->first();
    expect($key)->not->toBeNull()
        ->and($key->identity_holder)->toBe(OrgSecretKey::HOLDER_DPLY)
        ->and($key->public_recipient)->toStartWith('age1')
        ->and($key->public_recipient)->not->toBe('age1existingcustomerkey')
        ->and($key->dplyCanDecrypt())->toBeTrue();
});

test('dply-managed residency key can be rotated in place', function () {
    [$user, $org] = residencyOwnerWithOrg();
    OrgSecretKey::query()->create([
        'organization_id' => $org->id,
        'public_recipient' => 'age1olddplyheldkey',
        'identity_holder' => OrgSecretKey::HOLDER_DPLY,
        'dply_identity' => "# public key: age1olddplyheldkey\nAGE-SECRET-KEY-1OLD\n",
        'fingerprint' => substr(hash('sha256', 'age1olddplyheldkey'), 0, 12),
    ]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('tab', 'residency')
        ->call('confirmRotateEncryptionKey')
        ->assertSet('confirmActionModalMethod', 'rotateToNewDplyHeldKey')
        ->call('confirmActionModal');

    $key = $org->secretKey()->first();
    expect($key)->not->toBeNull()
        ->and($key->public_recipient)->toStartWith('age1')
        ->and($key->public_recipient)->not->toBe('age1olddplyheldkey')
        ->and($key->dplyCanDecrypt())->toBeTrue();
});

test('member cannot revert the residency key', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);
    seedCustomerHeldKey($org, 'age1existingcustomerkey');

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('tab', 'residency')
        ->call('confirmRevertToDplyHeld')
        ->assertForbidden();
});

/**
 * @return array{0: User, 1: Organization}
 */
function residencyOwnerWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

function seedCustomerHeldKey(Organization $org, string $recipient): void
{
    OrgSecretKey::query()->create([
        'organization_id' => $org->id,
        'public_recipient' => $recipient,
        'identity_holder' => OrgSecretKey::HOLDER_CUSTOMER,
        'dply_identity' => null,
        'fingerprint' => substr(hash('sha256', $recipient), 0, 12),
    ]);
}
