<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Services\Servers\SiteLoadAttributorScript;
use App\Support\Servers\HostContentionDetector;
use App\Support\Servers\SharedStackMapBuilder;
use App\Support\Servers\SiteLoadAttributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('site load attributor script parses scan output', function (): void {
    $output = <<<'OUT'
SCAN_BEGIN
SITE_BEGIN slug=alpha
mem_kb=204800
cpu_pct=42.5
SITE_END
SITE_BEGIN slug=beta
mem_kb=51200
cpu_pct=10.0
SITE_END
TOTAL_BEGIN
mem_kb=307200
cpu_pct=60.0
TOTAL_END
SCAN_END
OUT;

    $parsed = app(SiteLoadAttributorScript::class)->parse($output);

    expect($parsed['sites'])->toHaveCount(2)
        ->and($parsed['sites'][0]['slug'])->toBe('alpha')
        ->and($parsed['sites'][0]['mem_kb'])->toBe(204800)
        ->and($parsed['sites'][0]['cpu_pct'])->toBe(42.5)
        ->and($parsed['total']['mem_kb'])->toBe(307200)
        ->and($parsed['unattributed']['mem_kb'])->toBe(51200);
});

test('site load attributor formats rows from server meta snapshot', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'shared_host_attribution_snapshot' => [
                'checked_at' => now()->toIso8601String(),
                'sites' => [
                    ['slug' => 'alpha', 'mem_kb' => 102400, 'cpu_pct' => 30.0, 'mem_mb' => 100.0],
                    ['slug' => 'beta', 'mem_kb' => 51200, 'cpu_pct' => 10.0, 'mem_mb' => 50.0],
                ],
                'total' => ['mem_kb' => 153600, 'cpu_pct' => 40.0, 'mem_mb' => 150.0],
                'unattributed' => ['mem_kb' => 0, 'cpu_pct' => 0.0, 'mem_mb' => 0.0],
            ],
        ],
    ]);

    Site::factory()->count(2)->sequence(
        ['slug' => 'alpha', 'name' => 'Alpha App'],
        ['slug' => 'beta', 'name' => 'Beta Shop'],
    )->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $report = app(SiteLoadAttributor::class)->forServer($server->fresh('sites'));

    expect($report['has_snapshot'])->toBeTrue()
        ->and($report['solo_tenant'])->toBeFalse()
        ->and($report['rows'])->toHaveCount(2)
        ->and($report['rows'][0]['name'])->toBe('Alpha App')
        ->and($report['rows'][0]['cpu_share_pct'])->toBe(75.0);
});

test('shared stack map builder groups sites on same redis binding', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $sites = Site::factory()->count(2)->sequence(
        ['slug' => 'alpha', 'name' => 'Alpha'],
        ['slug' => 'beta', 'name' => 'Beta'],
    )->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    foreach ($sites as $site) {
        SiteBinding::query()->create([
            'site_id' => $site->id,
            'type' => 'redis',
            'mode' => 'managed',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'Shared Redis',
            'injected_env' => [
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => '6379',
            ],
        ]);
    }

    $map = app(SharedStackMapBuilder::class)->forServer($server->fresh(['sites.bindings']));

    expect($map['shared_resources'])->toHaveCount(1)
        ->and($map['shared_resources'][0]['site_count'])->toBe(2)
        ->and($map['shared_resources'][0]['type'])->toBe('redis');
});

test('host contention detector flags dominant site from attribution', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    Site::factory()->count(2)->sequence(
        ['slug' => 'alpha', 'name' => 'Alpha'],
        ['slug' => 'beta', 'name' => 'Beta'],
    )->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $attribution = [
        'has_snapshot' => true,
        'rows' => [
            [
                'slug' => 'alpha',
                'name' => 'Alpha',
                'href' => '/sites/alpha',
                'cpu_pct' => 80.0,
                'mem_mb' => 900.0,
                'cpu_share_pct' => 80.0,
                'mem_share_pct' => 75.0,
            ],
        ],
        'checked_at' => now(),
    ];

    $events = app(HostContentionDetector::class)->events($server->fresh('sites'), $attribution);

    expect($events)->not->toBeEmpty()
        ->and($events[0]['title'])->toBe(__('Noisy neighbor detected'));
});
