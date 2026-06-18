<?php

declare(strict_types=1);

namespace Tests\Feature\CreateCloudDatabaseTest;

use App\Modules\Cloud\Actions\CreateCloudDatabase;
use App\Modules\Cloud\Jobs\ProvisionCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function orgWithDoCredential(): Organization
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return $org;
}
test('creates provisioning row and dispatches job', function () {
    Bus::fake();
    $org = orgWithDoCredential();

    $db = (new CreateCloudDatabase)->handle($org, [
        'name' => 'acme-db',
        'engine' => 'postgres',
        'version' => '16',
        'size' => 'medium',
        'region' => 'nyc1',
    ]);

    expect($db->name)->toBe('acme-db');
    expect($db->status)->toBe(CloudDatabase::STATUS_PROVISIONING);
    expect($db->size)->toBe('medium');
    expect($db->backend)->toBe(CloudDatabase::BACKEND_DIGITALOCEAN);
    expect($db->provider_credential_id)->not->toBeNull();

    Bus::assertDispatched(ProvisionCloudDatabaseJob::class, fn ($j) => $j->cloudDatabaseId === $db->id);
});
test('rejects unknown engine', function () {
    $org = orgWithDoCredential();

    $this->expectException(\InvalidArgumentException::class);
    (new CreateCloudDatabase)->handle($org, ['name' => 'x', 'engine' => 'oracle']);
});
test('rejects missing name', function () {
    $org = orgWithDoCredential();

    $this->expectException(\InvalidArgumentException::class);
    (new CreateCloudDatabase)->handle($org, ['name' => '', 'engine' => 'postgres']);
});
test('fails without a do credential', function () {
    $org = Organization::factory()->create();

    $this->expectException(\RuntimeException::class);
    (new CreateCloudDatabase)->handle($org, ['name' => 'x', 'engine' => 'postgres']);
});
test('unknown size falls back to small', function () {
    Bus::fake();
    $org = orgWithDoCredential();

    $db = (new CreateCloudDatabase)->handle($org, [
        'name' => 'x',
        'engine' => 'postgres',
        'size' => 'enormous',
    ]);

    expect($db->size)->toBe('small');
});
