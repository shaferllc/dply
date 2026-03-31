<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerMetricsGuestPushVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerMetricsGuestPushVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_reports_current_guest_push_setup(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
                'monitoring_callback_env_deployed' => true,
                'monitoring_callback_env_deployed_at' => '2026-03-31T12:00:00Z',
                'monitoring_guest_cron_installed_at' => '2026-03-31T12:01:00Z',
                'monitoring_guest_push_cron_expression' => '* * * * *',
                'monitoring_guest_script_sha' => app(\App\Services\Servers\ServerMetricsGuestScript::class)->bundledSha256(),
                'monitoring_callback_env_present_remote' => true,
                'monitoring_guest_cron_present_remote' => true,
                'monitoring_guest_push_last_sample_at' => '2026-03-31T12:05:00Z',
            ],
        ]);

        $summary = app(ServerMetricsGuestPushVerifier::class)->summary($server);

        $this->assertTrue($summary['configured']);
        $this->assertTrue($summary['callback_env_deployed']);
        $this->assertTrue($summary['cron_installed']);
        $this->assertTrue($summary['cron_current']);
        $this->assertTrue($summary['script_current']);
        $this->assertSame('2026-03-31T12:05:00Z', $summary['last_guest_sample_at']);
    }
}
