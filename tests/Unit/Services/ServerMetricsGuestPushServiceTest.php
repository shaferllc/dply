<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerMetricsGuestPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_push_url_prefers_ingest_url_then_public_origin_then_app_url(): void
    {
        config([
            'server_metrics.ingest.url' => 'https://ingest.example.test/api/metrics',
            'dply.public_app_url' => 'https://public.example.test',
            'app.url' => 'http://localhost',
        ]);

        $service = app(ServerMetricsGuestPushService::class);
        $this->assertSame('https://ingest.example.test/api/metrics', $service->guestPushUrl());

        config(['server_metrics.ingest.url' => null]);
        $this->assertSame('https://public.example.test/api/metrics', $service->guestPushUrl());

        config(['dply.public_app_url' => null]);
        $this->assertSame('http://localhost/api/metrics', $service->guestPushUrl());
    }

    public function test_install_guest_metrics_cron_bash_contains_markers_and_schedule(): void
    {
        config(['server_metrics.guest_push.cron_expression' => '*/5 * * * *']);

        $bash = app(ServerMetricsGuestPushService::class)->installGuestMetricsCronBash();

        $this->assertStringContainsString('# BEGIN DPLY METRICS GUEST', $bash);
        $this->assertStringContainsString('# END DPLY METRICS GUEST', $bash);
        $this->assertStringContainsString('crontab', $bash);
        $decoded = $this->decodedCronLineFromBash($bash);
        $this->assertStringContainsString('server-metrics-snapshot.py', $decoded);
        $this->assertStringContainsString('*/5 * * * *', $decoded);
    }

    public function test_install_guest_metrics_cron_bash_falls_back_when_expression_invalid(): void
    {
        config(['server_metrics.guest_push.cron_expression' => 'not-a-valid-cron']);

        $bash = app(ServerMetricsGuestPushService::class)->installGuestMetricsCronBash();

        $decoded = $this->decodedCronLineFromBash($bash);
        $this->assertStringContainsString('*/5 * * * *', $decoded);
    }

    public function test_normalized_guest_push_cron_expression_trims_and_collapses_whitespace(): void
    {
        config(['server_metrics.guest_push.cron_expression' => "  0   *  \t* * *  "]);

        $this->assertSame('0 * * * *', app(ServerMetricsGuestPushService::class)->normalizedGuestPushCronExpression());
    }

    public function test_ensure_configured_requeues_when_public_callback_url_changes(): void
    {
        Queue::fake();

        config([
            'dply.public_app_url' => 'https://new-public.example.test',
            'app.url' => 'http://localhost',
        ]);

        $server = Server::factory()->create([
            'meta' => [
                'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
                'monitoring_callback_env_deployed' => true,
                'monitoring_guest_cron_installed_at' => now()->toIso8601String(),
                'monitoring_guest_push_cron_expression' => '* * * * *',
                'monitoring_guest_push_callback_url' => 'https://old-public.example.test/api/metrics',
            ],
        ]);

        app(ServerMetricsGuestPushService::class)->ensureConfigured($server);

        Queue::assertPushed(\App\Jobs\DeployGuestMetricsCallbackEnvJob::class);
    }

    public function test_ensure_configured_requeues_when_remote_callback_files_are_missing(): void
    {
        Queue::fake();

        $server = Server::factory()->create([
            'meta' => [
                'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
                'monitoring_callback_env_deployed' => true,
                'monitoring_guest_cron_installed_at' => now()->toIso8601String(),
                'monitoring_guest_push_cron_expression' => '* * * * *',
                'monitoring_callback_env_present_remote' => false,
                'monitoring_guest_cron_present_remote' => false,
            ],
        ]);

        app(ServerMetricsGuestPushService::class)->ensureConfigured($server);

        Queue::assertPushed(\App\Jobs\DeployGuestMetricsCallbackEnvJob::class);
    }

    private function decodedCronLineFromBash(string $bash): string
    {
        if (preg_match("/printf '%s' '([A-Za-z0-9+\\/=]+)' \\| base64 -d/", $bash, $m) !== 1) {
            $this->fail('Could not extract base64 cron line from bash.');
        }

        $raw = base64_decode($m[1], true);
        $this->assertIsString($raw);

        return $raw;
    }
}
