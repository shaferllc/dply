<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Imports;

use App\Jobs\Imports\SyncPloiInventoryJob;
use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiInventorySync;
use App\Services\Imports\Ploi\SyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class SyncPloiInventoryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_sync_all_when_no_server_filter(): void
    {
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
    }

    public function test_job_calls_sync_one_server_when_filter_set(): void
    {
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
    }

    public function test_job_is_noop_when_credential_missing_or_wrong_provider(): void
    {
        $credential = ProviderCredential::factory()->create(['provider' => 'digitalocean']);

        $sync = Mockery::mock(PloiInventorySync::class);
        $sync->shouldNotReceive('syncAll');
        $sync->shouldNotReceive('syncOneServer');

        (new SyncPloiInventoryJob($credential->id))->handle($sync);
        (new SyncPloiInventoryJob('ulid-that-does-not-exist'))->handle($sync);
    }
}
