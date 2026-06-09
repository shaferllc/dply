<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerSystemLogsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('server system logs report summarizes sources and viewer state', function (): void {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => 'test-key',
    ]);

    $logSources = [
        'dply_activity' => [
            'type' => 'dply',
            'label' => 'Dply activity',
            'group' => 'dply',
        ],
        'nginx_error' => [
            'type' => 'file',
            'label' => 'Nginx error',
            'path' => '/var/log/nginx/error.log',
            'group' => 'nginx',
        ],
    ];

    $report = app(ServerSystemLogsReport::class)->build($server, $logSources, [
        'log_key' => 'dply_activity',
        'log_total_lines' => 120,
        'log_filtered_lines' => 45,
        'log_last_fetched_at' => now()->subMinutes(2)->toIso8601String(),
        'log_auto_refresh' => true,
        'log_auto_refresh_seconds' => 30,
        'log_time_range_minutes' => null,
        'remote_log_error' => null,
        'log_last_fetch_truncated' => false,
        'log_last_fetch_raw_bytes' => 4096,
        'log_broadcast_subscribable' => true,
    ], $user);

    expect($report['overall'])->toBe('ready')
        ->and($report['ops_ready'])->toBeTrue()
        ->and($report['summary']['source_count'])->toBe(2)
        ->and($report['summary']['group_count'])->toBe(2)
        ->and($report['summary']['filtered_lines'])->toBe(45)
        ->and($report['active_source']['key'])->toBe('dply_activity')
        ->and($report['source_rows'])->toHaveCount(2)
        ->and($report['viewer']['raw_bytes'])->toBe(4096);
});

test('server system logs report marks blocked when ssh not ready for file source', function (): void {
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'setup_status' => Server::SETUP_STATUS_PENDING,
    ]);

    $logSources = [
        'nginx_error' => [
            'type' => 'file',
            'label' => 'Nginx error',
            'path' => '/var/log/nginx/error.log',
            'group' => 'nginx',
        ],
    ];

    $report = app(ServerSystemLogsReport::class)->build($server, $logSources, [
        'log_key' => 'nginx_error',
        'log_total_lines' => 0,
        'log_filtered_lines' => 0,
        'log_last_fetched_at' => null,
        'log_auto_refresh' => false,
        'log_auto_refresh_seconds' => 30,
        'log_time_range_minutes' => null,
        'remote_log_error' => null,
        'log_last_fetch_truncated' => false,
        'log_last_fetch_raw_bytes' => 0,
        'log_broadcast_subscribable' => false,
    ], $user);

    expect($report['overall'])->toBe('blocked')
        ->and($report['ops_ready'])->toBeFalse()
        ->and($report['ssh_required_for_active'])->toBeTrue();
});
