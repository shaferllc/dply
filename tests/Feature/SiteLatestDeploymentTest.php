<?php

declare(strict_types=1);

namespace Tests\Feature\SiteLatestDeploymentTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('latest deployment returns most recent by started at', function () {
    $site = makeSite();
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'old',
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subHour(),
    ]);
    $latest = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'newer',
        'trigger' => 'webhook',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinute(),
    ]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'older',
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now()->subHours(2),
    ]);

    $found = $site->latestDeployment();

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($latest->id);
});
test('latest deployment returns null when none recorded', function () {
    $site = makeSite();

    expect($site->latestDeployment())->toBeNull();
});
function makeSite(): Site
{
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $site->refresh();

    return $site;
}
