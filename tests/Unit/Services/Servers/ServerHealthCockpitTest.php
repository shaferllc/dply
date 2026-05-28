<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Services\Servers\ServerHealthCockpit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cockpit flags high cpu and failed deploys', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => [
            'cpu_pct' => 92.5,
            'mem_pct' => 40.0,
            'disk_pct' => 55.0,
            'load_1m' => 1.2,
        ],
    ]);

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'deploy_strategy' => 'atomic',
        'releases_to_keep' => 5,
    ]);

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'health-cockpit-fail-1',
        'status' => SiteDeployment::STATUS_FAILED,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'created_at' => now()->subDay(),
    ]);

    $report = app(ServerHealthCockpit::class)->forServer($server);

    expect($report['overall'])->toBeIn(['warning', 'critical']);
    expect($report['alert_count'])->toBeGreaterThan(0);
    expect(collect($report['alerts'])->contains(fn (array $a): bool => str_contains($a['title'], 'CPU')))->toBeTrue();
});

test('cockpit reports ok when metrics are healthy and no failures', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => [
            'cpu_pct' => 22.0,
            'mem_pct' => 35.0,
            'disk_pct' => 41.0,
        ],
    ]);

    $report = app(ServerHealthCockpit::class)->forServer($server);

    expect($report['overall'])->toBe('ok');
    expect($report['capacity']['headroom'])->toBe('high');
});
