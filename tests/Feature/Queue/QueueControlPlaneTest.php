<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueControlPlaneTest;

use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Models\User;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Actions\MintQueueCredential;
use App\Modules\Queue\Actions\RevokeQueueCredential;
use App\Modules\Queue\Actions\RotateQueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function queueOrg(): Organization
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $org;
}

beforeEach(function () {
    Cache::flush();
    config(['queue_service.entitlements.defaults.max_namespaces' => 5]);
    config(['queue_service.entitlements.plans' => []]);
});

test('creating a namespace mints a usable credential and reveals it once', function () {
    $result = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders');

    expect($result['namespace']->isActive())->toBeTrue();

    // The access key id is the public half — AWS-shaped, safe to display.
    expect($result['credential']->accessKeyId())->toStartWith('dplyq');
    expect(strlen($result['credential']->accessKeyId()))->toBe(20);

    // The hash still matches the secret: it remains the lookup/cache key and
    // the comparison for the native bearer path.
    expect($result['credential']->token_hash)->toBe(hash('sha256', $result['plaintext']));

    // The secret itself is recoverable — SigV4 needs it — but only through the
    // encrypted cast, never as ciphertext.
    expect($result['credential']->fresh()->secret)->toBe($result['plaintext']);
});

test('the secret is encrypted at rest, not stored in the clear', function () {
    // Reversible storage is forced by SigV4 (an HMAC over a shared secret),
    // so the protection is encryption rather than hashing — the same tradeoff
    // RealtimeApp::app_secret makes. The raw column must never be readable.
    $result = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders');

    $raw = DB::table('dply_queue_credentials')
        ->where('id', $result['credential']->id)
        ->value('secret');

    expect($raw)->not->toBe($result['plaintext']);
    expect($raw)->not->toContain($result['plaintext']);
});

test('the credential cache key is derivable from the stored row', function () {
    // This is the property the whole sha256 decision rests on: revocation can
    // evict the exact cache entry without knowing the plaintext. A salted hash
    // would make this impossible.
    $result = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders');

    expect($result['credential']->cacheKey())
        ->toBe(ServiceCredential::cacheKeyForHash(hash('sha256', $result['plaintext'])));
});

test('revoking a credential evicts its cache entry immediately', function () {
    $result = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders');
    $credential = $result['credential'];

    Cache::put($credential->cacheKey(), ['namespace_id' => $credential->namespace_id], 60);

    app(RevokeQueueCredential::class)->handle($credential);

    expect(Cache::get($credential->cacheKey()))->toBeNull();
    expect($credential->fresh()->isUsable())->toBeFalse();
});

test('revoking is idempotent', function () {
    $result = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders');
    $revoke = app(RevokeQueueCredential::class);

    $revoke->handle($result['credential']);
    $first = $result['credential']->fresh()->revoked_at;

    $revoke->handle($result['credential']->fresh());

    expect($result['credential']->fresh()->revoked_at->eq($first))->toBeTrue();
});

test('rotation leaves the old credential live so a redeploy can catch up', function () {
    // A .env only reaches the running app on its next deploy. Revoking on mint
    // would guarantee an outage for the length of that deploy.
    $org = queueOrg();
    $created = app(CreateQueueNamespace::class)->handle($org, 'orders');
    $namespace = $created['namespace'];

    $rotated = app(RotateQueueCredential::class)->handle($namespace);

    expect($namespace->liveCredentials())->toHaveCount(2);
    expect($created['credential']->fresh()->isUsable())->toBeTrue();
    expect($rotated['credential']->isUsable())->toBeTrue();
    expect($rotated['plaintext'])->not->toBe($created['plaintext']);
});

test('a third live credential is refused', function () {
    // Three means a previous rotation was never finished; allowing it would
    // let an abandoned secret live indefinitely.
    $namespace = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders')['namespace'];
    app(RotateQueueCredential::class)->handle($namespace);

    expect(fn () => app(RotateQueueCredential::class)->handle($namespace->fresh()))
        ->toThrow(\RuntimeException::class);
});

test('revoking one frees a rotation slot', function () {
    $namespace = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders')['namespace'];
    $second = app(RotateQueueCredential::class)->handle($namespace)['credential'];

    app(RevokeQueueCredential::class)->handle($second);

    expect($namespace->fresh()->liveCredentials())->toHaveCount(1);
    expect(fn () => app(RotateQueueCredential::class)->handle($namespace->fresh()))
        ->not->toThrow(\RuntimeException::class);
});

test('an expired credential is not usable and not counted as live', function () {
    $namespace = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders')['namespace'];

    $expired = (new MintQueueCredential)->handle($namespace, 'old', expiresAt: now()->subDay())['credential'];

    expect($expired->isUsable())->toBeFalse();
    expect($namespace->liveCredentials()->pluck('id'))->not->toContain($expired->id);
});

test('scopes gate push and pop independently', function () {
    $namespace = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders')['namespace'];

    $pushOnly = (new MintQueueCredential)->handle($namespace, 'producer', [ServiceCredential::SCOPE_PUSH])['credential'];

    expect($pushOnly->allows(ServiceCredential::SCOPE_PUSH))->toBeTrue();
    expect($pushOnly->allows(ServiceCredential::SCOPE_POP))->toBeFalse();
});

test('an empty scope list means unrestricted', function () {
    $namespace = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders')['namespace'];
    $credential = (new MintQueueCredential)->handle($namespace, 'legacy', [])['credential'];

    expect($credential->allows(ServiceCredential::SCOPE_PUSH))->toBeTrue();
    expect($credential->allows(ServiceCredential::SCOPE_POP))->toBeTrue();
});

test('bumping the epoch is how a namespace revokes everything at once', function () {
    $namespace = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders')['namespace'];
    $before = $namespace->credential_epoch;

    $namespace->bumpCredentialEpoch();

    expect($namespace->fresh()->credential_epoch)->toBe($before + 1);
});

test('the namespace limit is enforced from the plan entitlement', function () {
    config(['queue_service.entitlements.defaults.max_namespaces' => 1]);
    $org = queueOrg();

    app(CreateQueueNamespace::class)->handle($org, 'first');

    expect(fn () => app(CreateQueueNamespace::class)->handle($org, 'second'))
        ->toThrow(\RuntimeException::class);
});

test('a zero namespace limit means unlimited', function () {
    // The fail-open convention: nothing is enforced until a number is set.
    config(['queue_service.entitlements.defaults.max_namespaces' => 0]);
    $org = queueOrg();

    app(CreateQueueNamespace::class)->handle($org, 'first');
    app(CreateQueueNamespace::class)->handle($org, 'second');

    expect(QueueNamespace::query()->where('organization_id', $org->id)->count())->toBe(2);
});

test('an unavailable plan cannot create a namespace', function () {
    config(['queue_service.entitlements.defaults.available' => false]);

    expect(fn () => app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders'))
        ->toThrow(\RuntimeException::class);
});

test('the masked token identifies a credential without exposing it', function () {
    $result = app(CreateQueueNamespace::class)->handle(queueOrg(), 'orders');

    $masked = $result['credential']->maskedToken();

    expect($masked)->toStartWith('dplyq');
    expect($masked)->not->toContain($result['plaintext']);
});
