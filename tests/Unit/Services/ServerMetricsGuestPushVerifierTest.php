<?php


namespace Tests\Unit\Services\ServerMetricsGuestPushVerifierTest;
use App\Models\Server;
use App\Services\Servers\ServerMetricsGuestPushVerifier;
use App\Services\Servers\ServerMetricsGuestScript;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('summary reports current guest push setup', function () {
    $server = Server::factory()->create([
        'meta' => [
            'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
            'monitoring_callback_env_deployed' => true,
            'monitoring_callback_env_deployed_at' => '2026-03-31T12:00:00Z',
            'monitoring_guest_cron_installed_at' => '2026-03-31T12:01:00Z',
            'monitoring_guest_push_cron_expression' => '* * * * *',
            'monitoring_guest_script_sha' => app(ServerMetricsGuestScript::class)->bundledSha256(),
            'monitoring_callback_env_present_remote' => true,
            'monitoring_guest_cron_present_remote' => true,
            'monitoring_guest_push_last_sample_at' => '2026-03-31T12:05:00Z',
        ],
    ]);

    $summary = app(ServerMetricsGuestPushVerifier::class)->summary($server);

    expect($summary['configured'])->toBeTrue();
    expect($summary['callback_env_deployed'])->toBeTrue();
    expect($summary['cron_installed'])->toBeTrue();
    expect($summary['cron_current'])->toBeTrue();
    expect($summary['script_current'])->toBeTrue();
    expect($summary['last_guest_sample_at'])->toBe('2026-03-31T12:05:00Z');
});