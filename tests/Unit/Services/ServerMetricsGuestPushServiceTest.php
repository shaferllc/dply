<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerMetricsGuestPushService;
use Tests\TestCase;

class ServerMetricsGuestPushServiceTest extends TestCase
{
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
