<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BuildProviderCredentialHealthTest;

use App\Actions\Servers\BuildProviderCredentialHealth;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('reports ok when provider validation succeeds', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'abc']], 200),
    ]);

    $credential = digitalOceanCredential();

    $result = BuildProviderCredentialHealth::run('digitalocean', $credential);

    expect($result['status'])->toBe('ok');
    expect($result['severity'])->toBe('info');
});
test('reports under scoped when provider rejects access', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Forbidden'], 403),
    ]);

    $credential = digitalOceanCredential();

    $result = BuildProviderCredentialHealth::run('digitalocean', $credential);

    expect($result['status'])->toBe('under_scoped');
    expect($result['severity'])->toBe('error');
});
test('reports rate limited when provider is rate limited', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Rate limit exceeded'], 429),
    ]);

    $credential = digitalOceanCredential();

    $result = BuildProviderCredentialHealth::run('digitalocean', $credential);

    expect($result['status'])->toBe('rate_limited');
    expect($result['severity'])->toBe('warning');
});
function digitalOceanCredential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Primary DO',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
}
