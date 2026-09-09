<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueCredentialRevokeUiTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Actions\RotateQueueCredential;
use App\Modules\Queue\Livewire\QueueNamespaceShow;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The revoke half of rotation. Whether it works at all is covered by the page
 * spec in tests/Feature/Livewire/Queue/QueuesPageTest.php; this file covers the
 * states around it that are easy to get quietly wrong.
 */
beforeEach(function () {
    Cache::flush();
    config(['queue_service.enabled' => true]);
    config(['queue_service.entitlements.defaults.max_namespaces' => 5]);
    config(['queue_service.entitlements.plans' => []]);
});

/** @return array{0: User, 1: QueueNamespace} */
function revokeFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $namespace = app(CreateQueueNamespace::class)->handle($org, 'orders')['namespace'];

    return [$user, $namespace];
}

it('leaves the replacement credential working', function () {
    [$user, $namespace] = revokeFixture();

    $old = $namespace->liveCredentials()->first();
    app(RotateQueueCredential::class)->handle($namespace, 'Replacement', $user->id);

    Livewire::actingAs($user)
        ->test(QueueNamespaceShow::class, ['queueNamespace' => $namespace])
        ->call('confirmRevoke', $old->id)
        ->call('revokeCredential');

    expect($old->fresh()->isRevoked())->toBeTrue();
    // The whole point of the pair: the new one is untouched.
    expect($namespace->fresh()->liveCredentials())->toHaveCount(1);
});

it('warns before revoking the last credential rather than blocking it', function () {
    [$user, $namespace] = revokeFixture();

    $only = $namespace->liveCredentials()->first();

    // Allowed — an operator cutting off a compromised key should not have to
    // mint a replacement first — but the modal has to say what it costs.
    Livewire::actingAs($user)
        ->test(QueueNamespaceShow::class, ['queueNamespace' => $namespace])
        ->set('tab', 'credentials')
        ->call('confirmRevoke', $only->id)
        ->assertSee('only live credential');
});

it('reports an already-revoked credential rather than pretending to act', function () {
    [$user, $namespace] = revokeFixture();

    $old = $namespace->liveCredentials()->first();
    app(RotateQueueCredential::class)->handle($namespace, 'Replacement', $user->id);

    $component = Livewire::actingAs($user)
        ->test(QueueNamespaceShow::class, ['queueNamespace' => $namespace])
        ->call('confirmRevoke', $old->id)
        ->call('revokeCredential');

    // Re-arming the same credential and revoking again must not read as success.
    $component->call('confirmRevoke', $old->id)
        ->call('revokeCredential')
        ->assertDispatched('notify', fn (string $e, array $p): bool => ($p['type'] ?? '') === 'warning');
});

it('surfaces that the old credential is still being used', function () {
    [$user, $namespace] = revokeFixture();

    $old = $namespace->liveCredentials()->first();
    $old->forceFill(['last_used_at' => now()->subMinutes(3)])->save();
    app(RotateQueueCredential::class)->handle($namespace, 'Replacement', $user->id);

    // last_used_at is tracked precisely so an operator knows whether the
    // redeploy has landed, instead of guessing whether the cut is safe.
    Livewire::actingAs($user)
        ->test(QueueNamespaceShow::class, ['queueNamespace' => $namespace])
        ->set('tab', 'credentials')
        ->call('confirmRevoke', $old->id)
        ->assertSee('Still in use');
});

it('offers the control on the credentials tab', function () {
    [$user, $namespace] = revokeFixture();
    app(RotateQueueCredential::class)->handle($namespace, 'Replacement', $user->id);

    // The page tells operators to "mint, deploy, then revoke the old" — the
    // control has to actually be there for that sentence to be true.
    Livewire::actingAs($user)
        ->test(QueueNamespaceShow::class, ['queueNamespace' => $namespace])
        ->set('tab', 'credentials')
        ->assertSee('Revoke');
});
