<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Imports\SyncPloiInventoryJobTest;
use App\Jobs\Imports\SyncPloiInventoryJob;
use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiInventorySync;
use App\Services\Imports\SyncResult;
use Illuminate\Support\Carbon;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('job calls sync all when no server filter', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test_token'],
    ]);

    $sync = Mockery::mock(PloiInventorySync::class);
    $sync->shouldReceive('syncAll')
        ->once()
        ->with(Mockery::on(fn (ProviderCredential $c): bool => $c->id === $credential->id))
        ->andReturn(new SyncResult(0, 0, Carbon::now()));

    (new SyncPloiInventoryJob($credential->id))->handle($sync);
});
test('job calls sync one server when filter set', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test_token'],
    ]);

    $sync = Mockery::mock(PloiInventorySync::class);
    $sync->shouldReceive('syncOneServer')
        ->once()
        ->with(Mockery::on(fn (ProviderCredential $c): bool => $c->id === $credential->id), 42)
        ->andReturn(new SyncResult(1, 0, Carbon::now()));

    (new SyncPloiInventoryJob($credential->id, 42))->handle($sync);
});
test('job is noop when credential missing or wrong provider', function () {
    $credential = ProviderCredential::factory()->create(['provider' => 'digitalocean']);

    $sync = Mockery::mock(PloiInventorySync::class);
    $sync->shouldNotReceive('syncAll');
    $sync->shouldNotReceive('syncOneServer');

    (new SyncPloiInventoryJob($credential->id))->handle($sync);
    (new SyncPloiInventoryJob('ulid-that-does-not-exist'))->handle($sync);
});
