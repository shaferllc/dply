<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteRelease;
use App\Models\User;
use App\Services\Servers\ServerReleaseHygiene;
use App\Services\Servers\ServerReleaseHygieneScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('release hygiene flags extra releases and large logs', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'release_hygiene_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'sites' => [[
                    'slug' => 'app',
                    'release_count' => 8,
                    'extra' => 3,
                    'release_bytes' => 1200 * 1024 * 1024,
                    'laravel_log_bytes' => 30 * 1024 * 1024,
                    'laravel_log_path' => '/var/www/app/shared/storage/logs/laravel.log',
                    'failed_jobs' => 12,
                ]],
                'system' => [
                    'journal_usage' => 'Archived and active journals take up 128.0M',
                    'logfiles' => [[
                        'path' => '/var/log/nginx/access.log',
                        'bytes' => 1024 * 1024,
                    ]],
                ],
            ],
        ],
    ]);

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'slug' => 'app',
        'deploy_strategy' => 'atomic',
        'releases_to_keep' => 5,
    ]);

    SiteRelease::query()->create([
        'site_id' => $site->id,
        'folder' => '20260101',
        'is_active' => true,
    ]);
    SiteRelease::query()->create([
        'site_id' => $site->id,
        'folder' => '20260102',
        'is_active' => false,
    ]);

    $report = app(ServerReleaseHygiene::class)->forServer($server);

    expect($report['overall'])->toBeIn(['warning', 'critical'])
        ->and($report['releases']['sites_over_keep'])->toBe(1)
        ->and($report['failed_jobs']['total'])->toBe(12)
        ->and($report['logs']['laravel_total_bytes'])->toBe(30 * 1024 * 1024)
        ->and($report['logs']['site_rows'])->toHaveCount(1)
        ->and($report['logs']['site_rows'][0]['path'])->toBe('/var/www/app/shared/storage/logs/laravel.log');
});

test('release hygiene script parse captures site and system blocks', function (): void {
    $output = <<<'OUT'
SCAN_BEGIN
SITE_BEGIN slug=demo
release_count=6
extra=1
release_bytes=4096
laravel_log_bytes=2048
laravel_log_path=/var/www/demo/shared/storage/logs/laravel.log
failed_jobs=3
SITE_END
SYS_BEGIN
journal_usage=Archived and active journals take up 64.0M
logfile path=/var/log/nginx/error.log bytes=1024
SYS_END
SCAN_END
OUT;

    $meta = app(ServerReleaseHygieneScript::class)->parse($output, []);

    expect($meta['release_hygiene_snapshot']['sites'])->toHaveCount(1)
        ->and($meta['release_hygiene_snapshot']['sites'][0]['slug'])->toBe('demo')
        ->and($meta['release_hygiene_snapshot']['sites'][0]['failed_jobs'])->toBe(3)
        ->and($meta['release_hygiene_snapshot']['sites'][0]['laravel_log_path'])->toBe('/var/www/demo/shared/storage/logs/laravel.log')
        ->and($meta['release_hygiene_snapshot']['system']['journal_usage'])->toContain('64.0M');
});

test('release hygiene warns when scan never ran', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $report = app(ServerReleaseHygiene::class)->forServer($server);

    expect($report['scan']['never_scanned'])->toBeTrue()
        ->and(collect($report['alerts'])->contains(fn (array $a): bool => str_contains($a['title'], 'No hygiene scan')))->toBeTrue();
});

test('release hygiene marks stale scan', function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'release_hygiene_snapshot' => [
                'checked_at' => now()->subHours(48)->toIso8601String(),
                'sites' => [],
                'system' => [],
            ],
        ],
    ]);

    $report = app(ServerReleaseHygiene::class)->forServer($server);

    expect($report['scan']['stale'])->toBeTrue()
        ->and(collect($report['alerts'])->contains(fn (array $a): bool => str_contains($a['title'], 'stale')))->toBeTrue();

    Carbon::setTestNow();
});
