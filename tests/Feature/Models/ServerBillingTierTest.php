<?php

namespace Tests\Feature\Models;

use App\Enums\ServerTier;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerBillingTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_xs_when_no_metrics_have_been_collected(): void
    {
        $server = Server::factory()->create();

        $this->assertSame(ServerTier::XS, $server->billingTier());
    }

    public function test_classifies_from_latest_snapshot(): void
    {
        $server = Server::factory()->create();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => '2026-05-01T00:00:00Z',
            'payload' => [
                'cpu_count' => 4,
                'mem_total_kb' => 8 * 1024 * 1024,
            ],
        ]);

        $this->assertSame(ServerTier::M, $server->billingTier());
    }

    public function test_uses_most_recent_snapshot_when_multiple_exist(): void
    {
        $server = Server::factory()->create();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => '2026-05-01T00:00:00Z',
            'payload' => ['cpu_count' => 1, 'mem_total_kb' => 1024 * 1024],
        ]);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => '2026-05-02T00:00:00Z',
            'payload' => ['cpu_count' => 8, 'mem_total_kb' => 16 * 1024 * 1024],
        ]);

        $this->assertSame(ServerTier::L, $server->billingTier());
    }

    public function test_falls_back_to_xs_when_payload_missing_spec_fields(): void
    {
        $server = Server::factory()->create();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => '2026-05-01T00:00:00Z',
            'payload' => ['cpu_pct' => 12.0],
        ]);

        $this->assertSame(ServerTier::XS, $server->billingTier());
    }
}
