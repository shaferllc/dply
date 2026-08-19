<?php

declare(strict_types=1);

use App\Models\NotificationInboxItem;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('a rejected provider token produces one inbox notification, deduped across runs', function () {
    Http::fake([
        'api.digitalocean.com/*' => Http::response(['message' => 'Unable to authenticate you'], 401),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'dsds',
        'credentials' => ['api_token' => 'dop_v1_dead'],
    ]);

    $this->artisan('dply:provider-credentials:check')->assertSuccessful();
    $this->artisan('dply:provider-credentials:check')->assertSuccessful();

    $items = NotificationInboxItem::query()
        ->where('user_id', $user->id)
        ->where('metadata->kind', 'provider_credential_health')
        ->where('metadata->credential_id', $credential->id)
        ->get();

    expect($items)->toHaveCount(1)
        ->and($items->first()->url)->toContain('/credentials')
        ->and($credential->fresh()->isUnhealthy())->toBeTrue();
});

test('a healthy provider token does not notify', function () {
    Http::fake([
        'api.digitalocean.com/*' => Http::response(['account' => ['uuid' => 'ok']], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => User::factory()->create()->id,
        'organization_id' => Organization::factory()->create()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_ok'],
    ]);

    $this->artisan('dply:provider-credentials:check')->assertSuccessful();

    expect(NotificationInboxItem::query()->where('metadata->credential_id', $credential->id)->exists())->toBeFalse()
        ->and($credential->fresh()->validation_error)->toBeNull()
        ->and($credential->fresh()->last_validated_at)->not->toBeNull();
});
