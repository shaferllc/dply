<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Providers\ProviderCredentialHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function providerTokenHealthCredential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'dsds',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
}

it('stamps a healthy DigitalOcean token', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'abc']], 200),
    ]);

    $credential = providerTokenHealthCredential();

    expect(app(ProviderCredentialHealth::class)->refresh($credential, force: true))->toBeTrue()
        ->and($credential->fresh()->last_validated_at)->not->toBeNull()
        ->and($credential->fresh()->validation_error)->toBeNull();
});

it('stamps a rejected DigitalOcean token as unhealthy', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Unable to authenticate you'], 401),
    ]);

    $credential = providerTokenHealthCredential();

    expect(app(ProviderCredentialHealth::class)->refresh($credential, force: true))->toBeFalse()
        ->and($credential->fresh()->isUnhealthy())->toBeTrue()
        ->and($credential->fresh()->validation_error)->toContain('Unable to authenticate you');
});

it('does not mark a token unhealthy when the provider is unreachable', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: timed out');
    });

    $credential = providerTokenHealthCredential();

    expect(app(ProviderCredentialHealth::class)->refresh($credential, force: true))->toBeNull()
        ->and($credential->fresh()->validation_error)->toBeNull();
});
