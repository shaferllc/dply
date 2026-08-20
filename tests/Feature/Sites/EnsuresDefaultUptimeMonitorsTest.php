<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\EnsuresDefaultUptimeMonitorsTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use App\Services\Sites\EnsuresDefaultUptimeMonitors;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it seeds the http and https homepage pair with the host region', function () {
    $site = makeDefaultMonitorSite(serverRegion: 'sfo3');
    SiteUptimeMonitor::query()->where('site_id', $site->id)->delete();

    $created = app(EnsuresDefaultUptimeMonitors::class)->ensure($site->fresh());

    expect($created)->toHaveCount(2);
    $monitors = SiteUptimeMonitor::query()->where('site_id', $site->id)->orderBy('sort_order')->get();
    expect($monitors->pluck('label')->all())->toBe(['Homepage (HTTPS)', 'Homepage (HTTP)']);
    expect($monitors->pluck('check_type')->all())->toBe([
        SiteUptimeMonitor::CHECK_HTTPS,
        SiteUptimeMonitor::CHECK_HTTP,
    ]);
    expect($monitors->every(fn (SiteUptimeMonitor $monitor): bool => $monitor->probe_region === 'us-west'))->toBeTrue();
});

test('it does not add the default pair when only custom monitors exist', function () {
    $site = makeDefaultMonitorSite();
    SiteUptimeMonitor::query()->where('site_id', $site->id)->delete();
    SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'label' => 'API health',
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
        'probe_region' => 'eu-falkenstein',
    ]);

    $created = app(EnsuresDefaultUptimeMonitors::class)->ensure($site->fresh(['uptimeMonitors']));

    expect($created)->toBeEmpty();
    expect(SiteUptimeMonitor::query()->where('site_id', $site->id)->count())->toBe(1);
    expect(SiteUptimeMonitor::query()->where('site_id', $site->id)->value('probe_region'))->toBe('us-east');
});

function makeDefaultMonitorSite(?string $serverRegion = 'nyc1'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'region' => $serverRegion,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
}
