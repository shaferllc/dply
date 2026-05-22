<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDefaultMonitorTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

    $monitors = SiteUptimeMonitor::query()->where('site_id', $site->id)->get();

    expect($monitors)->toHaveCount(1);
    $monitor = $monitors->first();
    expect($monitor->path)->toBeNull();
    expect((int) $monitor->sort_order)->toBe(0);
    expect($monitor->probe_region)->not->toBeEmpty();
    expect($monitor->label)->not->toBeEmpty();
});
