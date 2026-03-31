<?php

namespace Tests\Unit\Jobs;

use App\Jobs\PushServerMetricSnapshotToIngestJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use App\Services\Servers\ServerMetricsIngestClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushServerMetricSnapshotToIngestJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_posts_snapshot_payload_to_configured_url(): void
    {
        Config::set('server_metrics.ingest.enabled', true);
        Config::set('server_metrics.ingest.url', 'https://dplyi.tunnel.dply.io/api/metrics');
        Config::set('server_metrics.ingest.token', 'test-secret');
        Config::set('server_metrics.ingest.timeout', 10);

        Http::fake([
            'https://dplyi.tunnel.dply.io/api/metrics' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'web-1',
        ]);
        $snapshot = ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => ['cpu_pct' => 12.5, 'mem_pct' => 40.0],
        ]);

        app(PushServerMetricSnapshotToIngestJob::class, ['serverMetricSnapshotId' => $snapshot->id])
            ->handle(app(ServerMetricsIngestClient::class));

        Http::assertSent(function ($request) use ($server, $snapshot) {
            return $request->url() === 'https://dplyi.tunnel.dply.io/api/metrics'
                && $request->hasHeader('Authorization', 'Bearer test-secret')
                && $request['server_id'] === $server->id
                && $request['organization_id'] === $server->organization_id
                && $request['server_name'] === 'web-1'
                && $request['snapshot_id'] === $snapshot->id
                && $request['metrics']['cpu_pct'] === 12.5;
        });
    }

    public function test_job_no_ops_when_ingest_disabled(): void
    {
        Config::set('server_metrics.ingest.enabled', false);
        Http::fake();

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $snapshot = ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [],
        ]);

        app(PushServerMetricSnapshotToIngestJob::class, ['serverMetricSnapshotId' => $snapshot->id])
            ->handle(app(ServerMetricsIngestClient::class));

        Http::assertNothingSent();
    }
}
