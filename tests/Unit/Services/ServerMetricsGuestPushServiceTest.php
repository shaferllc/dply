<?php

namespace Tests\Unit\Services\ServerMetricsGuestPushServiceTest;

use App\Jobs\DeployGuestMetricsCallbackEnvJob;
use App\Models\Server;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('guest push url prefers ingest url then public origin then app url', function () {
    config([
        'server_metrics.ingest.url' => 'https://ingest.example.test/api/metrics',
        'dply.public_app_url' => 'https://public.example.test',
        'app.url' => 'http://localhost',
    ]);

    $service = app(ServerMetricsGuestPushService::class);
    expect($service->guestPushUrl())->toBe('https://ingest.example.test/api/metrics');

    config(['server_metrics.ingest.url' => null]);
    expect($service->guestPushUrl())->toBe('https://public.example.test/api/metrics');

    config(['dply.public_app_url' => null]);
    expect($service->guestPushUrl())->toBe('http://localhost/api/metrics');
});

test('install guest metrics cron bash contains markers and schedule', function () {
    config(['server_metrics.guest_push.cron_expression' => '*/5 * * * *']);

    $bash = app(ServerMetricsGuestPushService::class)->installGuestMetricsCronBash();

    $this->assertStringContainsString('# BEGIN DPLY METRICS GUEST', $bash);
    $this->assertStringContainsString('# END DPLY METRICS GUEST', $bash);
    $this->assertStringContainsString('crontab', $bash);
    $decoded = decodedCronLineFromBash($bash);
    $this->assertStringContainsString('server-metrics-snapshot.py', $decoded);
    $this->assertStringContainsString('*/5 * * * *', $decoded);
});

test('install guest metrics cron bash falls back when expression invalid', function () {
    config(['server_metrics.guest_push.cron_expression' => 'not-a-valid-cron']);

    $bash = app(ServerMetricsGuestPushService::class)->installGuestMetricsCronBash();

    $decoded = decodedCronLineFromBash($bash);
    $this->assertStringContainsString('*/5 * * * *', $decoded);
});

test('normalized guest push cron expression trims and collapses whitespace', function () {
    config(['server_metrics.guest_push.cron_expression' => "  0   *  \t* * *  "]);

    expect(app(ServerMetricsGuestPushService::class)->normalizedGuestPushCronExpression())->toBe('0 * * * *');
});

test('ensure configured requeues when public callback url changes', function () {
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

    Queue::assertPushed(DeployGuestMetricsCallbackEnvJob::class);
});

test('ensure configured requeues when remote callback files are missing', function () {
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

    Queue::assertPushed(DeployGuestMetricsCallbackEnvJob::class);
});

function decodedCronLineFromBash(string $bash): string
{
    if (preg_match("/printf '%s' '([A-Za-z0-9+\\/=]+)' \\| base64 -d/", $bash, $m) !== 1) {
        $this->fail('Could not extract base64 cron line from bash.');
    }

    $raw = base64_decode($m[1], true);
    expect($raw)->toBeString();

    return $raw;
}
