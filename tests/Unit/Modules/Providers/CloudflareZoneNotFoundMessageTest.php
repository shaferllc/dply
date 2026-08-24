<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * "Zone [x] was not found in this Cloudflare account" is useless on its own.
 * The token could be dply's platform token, a customer's connected credential,
 * or stale env on one worker — and the old message could not tell them apart.
 * These lock in that the failure identifies the token, and never prints it.
 */
beforeEach(function () {
    // One canonical token now — services.cloudflare.key.
    config(['services.cloudflare.key' => 'platform-token-aaaa']);
});

/** No zone matches the lookup; the token can see two unrelated zones. */
function fakeZoneMiss(): void
{
    Http::fake(function ($request) {
        $isNameLookup = str_contains($request->url(), 'name=');

        return Http::response([
            'success' => true,
            'result' => $isNameLookup ? [] : [
                ['id' => 'z1', 'name' => 'example.com'],
                ['id' => 'z2', 'name' => 'other.dev'],
            ],
        ]);
    });
}

test('the error identifies the platform token by name', function () {
    fakeZoneMiss();

    expect(fn () => (new CloudflareDnsService('platform-token-aaaa'))->upsertARecord('on-dply.cc', 'x', '1.2.3.4'))
        ->toThrow(RuntimeException::class, 'CLOUDFLARE_KEY');
});

test('a customer credential is called out as a customer credential', function () {
    fakeZoneMiss();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'name' => 'Acme CF',
        'credentials' => ['api_token' => 'customer-token-cccc'],
    ]);

    expect(fn () => (new CloudflareDnsService($credential))->upsertARecord('on-dply.cc', 'x', '1.2.3.4'))
        ->toThrow(RuntimeException::class, 'customer credential "Acme CF"');
});

test('a token that is not the configured one is flagged as such', function () {
    fakeZoneMiss();

    expect(fn () => (new CloudflareDnsService('some-orphan-token'))->upsertARecord('on-dply.cc', 'x', '1.2.3.4'))
        ->toThrow(RuntimeException::class, 'NOT the configured');
});

test('the message lists what the token CAN see, which is the actual diagnosis', function () {
    fakeZoneMiss();

    expect(fn () => (new CloudflareDnsService('platform-token-aaaa'))->upsertARecord('on-dply.cc', 'x', '1.2.3.4'))
        ->toThrow(RuntimeException::class, 'example.com');
});

test('the token itself is never printed', function () {
    fakeZoneMiss();

    try {
        (new CloudflareDnsService('platform-token-aaaa'))->upsertARecord('on-dply.cc', 'x', '1.2.3.4');
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    // Only the last four characters may appear, never the whole secret.
    expect($message)->not->toContain('platform-token-aaaa')
        ->and($message)->toContain('aaaa')
        ->and($message)->toContain('sha256:');
});
