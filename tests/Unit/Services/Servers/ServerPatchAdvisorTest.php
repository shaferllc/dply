<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerPatchAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('patch advisor flags reboot required and security updates', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'inventory_checked_at' => now()->subHour()->toIso8601String(),
            'inventory_reboot_required' => true,
            'inventory_upgradable_packages' => 2,
            'inventory_upgradable_preview' => implode("\n", [
                'Listing...',
                'openssl/noble-security 3.0.13-0ubuntu3.6 amd64 [upgradable from: 3.0.13-0ubuntu3.5]',
                'curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]',
            ]),
            'inventory_os_pretty' => 'Ubuntu 24.04.2 LTS',
            'inventory_extended_snapshot' => "section1\n---\n 14:22:01 up 12 days,  3:04,  1 user,  load average: 0.08, 0.04, 0.01",
            'manage_last_apt_update' => now()->subDays(10)->toIso8601String(),
        ],
    ]);

    $report = app(ServerPatchAdvisor::class)->forServer($server);

    expect($report['overall'])->toBe('critical')
        ->and($report['packages']['security'])->toBe(1)
        ->and($report['packages']['total'])->toBe(2)
        ->and($report['reboot']['required'])->toBeTrue()
        ->and($report['uptime']['load'])->toBe('0.08, 0.04, 0.01')
        ->and(collect($report['alerts'])->contains(fn (array $a): bool => str_contains($a['title'], 'Reboot')))->toBeTrue();
});

test('patch advisor parseUpgradableRows extracts security sources', function (): void {
    $advisor = app(ServerPatchAdvisor::class);

    $rows = $advisor->parseUpgradableRows(
        "openssl/noble-security 3.0.13 amd64 [upgradable from: 3.0.13-0]\n".
        "vim/noble-updates 2:9.1 amd64\n",
    );

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['is_security'])->toBeTrue()
        ->and($rows[1]['is_security'])->toBeFalse();
});

test('patch advisor warns when inventory never scanned', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $report = app(ServerPatchAdvisor::class)->forServer($server);

    expect($report['overall'])->toBe('warning')
        ->and($report['inventory']['never_scanned'])->toBeTrue()
        ->and(collect($report['alerts'])->contains(fn (array $a): bool => str_contains($a['title'], 'No inventory scan')))->toBeTrue();
});

test('patch advisor marks stale inventory', function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'inventory_checked_at' => now()->subHours(48)->toIso8601String(),
            'inventory_upgradable_packages' => 0,
            'inventory_reboot_required' => false,
        ],
    ]);

    $report = app(ServerPatchAdvisor::class)->forServer($server);

    expect($report['inventory']['stale'])->toBeTrue()
        ->and(collect($report['alerts'])->contains(fn (array $a): bool => str_contains($a['title'], 'stale')))->toBeTrue();

    Carbon::setTestNow();
});
