<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDefaultMonitorTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('site creation auto creates default uptime monitor', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $monitors = SiteUptimeMonitor::query()->where('site_id', $site->id)->orderBy('sort_order')->get();

    expect($monitors)->toHaveCount(2);
    expect($monitors->pluck('label')->all())->toBe(['Homepage (HTTPS)', 'Homepage (HTTP)']);
    expect($monitors->pluck('check_type')->all())->toBe([
        SiteUptimeMonitor::CHECK_HTTPS,
        SiteUptimeMonitor::CHECK_HTTP,
    ]);
    expect($monitors->every(fn (SiteUptimeMonitor $monitor): bool => $monitor->path === null))->toBeTrue();
    expect($monitors->every(fn (SiteUptimeMonitor $monitor): bool => $monitor->probe_region !== ''))->toBeTrue();
});
