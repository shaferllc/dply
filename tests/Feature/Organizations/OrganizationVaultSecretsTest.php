<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationVaultSecretsTest;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\User;
use App\Modules\Secrets\Livewire\Secrets as OrganizationsSecrets;
use App\Modules\Secrets\Services\OrgSecretKeyManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

/*
 * The add/rotate/add-store forms moved into modals (2026-08-22). These cover
 * the open/close handshake and the confirm prompts that used to be built inline
 * in Blade with six @js() arguments.
 */

test('saving a secret closes the modal and clears the form', function () {
    [$user, $org] = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('openNewSecretModal')
        ->assertDispatched('open-modal', 'new-secret-modal')
        ->set('vault_key', 'MAILGUN_KEY')
        ->set('vault_value', 'key-abc123')
        ->call('createVaultSecret')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'new-secret-modal')
        ->assertSet('vault_key', '')
        ->assertSet('vault_value', '');
});

test('a rejected secret leaves the modal open with its error', function () {
    [$user, $org] = ownerWithOrg();
    OrganizationSecret::factory()->create(['organization_id' => $org->id, 'key' => 'STRIPE_SECRET', 'notes' => null]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('openNewSecretModal')
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'another')
        ->call('createVaultSecret')
        ->assertHasErrors(['vault_notes'])
        ->assertNotDispatched('close-modal')
        ->assertSet('vault_value', 'another');
});

test('rotate opens a modal carrying the key, and closes on save', function () {
    [$user, $org] = ownerWithOrg();
    $secret = OrganizationSecret::factory()->create(['organization_id' => $org->id, 'key' => 'DATABASE_URL']);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('startRotateVaultSecret', (string) $secret->id)
        ->assertDispatched('open-modal', 'rotate-secret-modal')
        ->assertSet('rotating_secret_key', 'DATABASE_URL')
        ->set('rotate_value', 'postgres://new')
        ->call('rotateVaultSecret')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'rotate-secret-modal')
        ->assertSet('rotating_secret_id', null)
        ->assertSet('rotating_secret_key', '');

    expect($secret->fresh()->value)->toBe('postgres://new');
});

test('a secret from another organization cannot be rotated', function () {
    [$user, $org] = ownerWithOrg();
    $foreign = OrganizationSecret::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    // secretForOrg() re-resolves the id inside the org, so a tampered id from
    // elsewhere never reaches the rotate — it 404s at the lookup.
    expect(fn () => Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('startRotateVaultSecret', (string) $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

test('the delete prompt names the key it is about to delete', function () {
    [$user, $org] = ownerWithOrg();
    $secret = OrganizationSecret::factory()->create(['organization_id' => $org->id, 'key' => 'SENTRY_DSN']);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('promptDeleteVaultSecret', (string) $secret->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'deleteVaultSecret')
        ->assertSee('SENTRY_DSN');
});

test('the bulk delete prompt counts the selection and no-ops when empty', function () {
    [$user, $org] = ownerWithOrg();
    $a = OrganizationSecret::factory()->create(['organization_id' => $org->id, 'key' => 'A_KEY']);
    $b = OrganizationSecret::factory()->create(['organization_id' => $org->id, 'key' => 'B_KEY']);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('promptDeleteSelectedVaultSecrets')
        ->assertSet('showConfirmActionModal', false)
        ->set('selected_secret_ids', [(string) $a->id, (string) $b->id])
        ->call('promptDeleteSelectedVaultSecrets')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'deleteSelectedVaultSecrets');
});

test('adopting a recipient hands off to the confirm dialog without stacking modals', function () {
    [$user, $org] = ownerWithOrg();
    app(OrgSecretKeyManager::class)->ensureForOrg($org->id);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('openAdoptRecipientModal')
        ->assertDispatched('open-modal', 'adopt-recipient-modal')
        ->set('recipient_input', 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p')
        ->call('adoptRecipient')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'adopt-recipient-modal')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'applyAdoptRecipient');
});

test('a recipient that is not an age key keeps the modal open', function () {
    [$user, $org] = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('openAdoptRecipientModal')
        ->set('recipient_input', 'ssh-rsa AAAA')
        ->call('adoptRecipient')
        ->assertHasErrors(['recipient_input'])
        ->assertNotDispatched('close-modal')
        ->assertSet('showConfirmActionModal', false);
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
