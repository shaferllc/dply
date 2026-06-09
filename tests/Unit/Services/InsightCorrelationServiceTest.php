<?php

namespace Tests\Unit\Services\InsightCorrelationServiceTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Services\Insights\InsightCorrelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeServerWithSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '10.0.0.1',
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$server, $site];
}

test('correlates successful site deployment', function () {
    [$server, $site] = makeServerWithSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'git_sha' => 'abc',
        'started_at' => now()->subMinutes(30),
        'finished_at' => now()->subMinutes(29),
    ]);

    $svc = app(InsightCorrelationService::class);
    $c = $svc->correlateForNewFinding($server->fresh());

    expect($c)->toBeArray();
    expect($c['type'])->toBe('site_deployment');
    expect($c['git_sha'])->toBe('abc');
});

test('prefers newer firewall apply over older deploy', function () {
    [$server, $site] = makeServerWithSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subHours(5),
        'finished_at' => now()->subHours(5),
    ]);

    ServerFirewallAuditEvent::query()->create([
        'server_id' => $server->id,
        'event' => ServerFirewallAuditEvent::EVENT_APPLY,
        'meta' => ['output_excerpt' => 'ok'],
        'created_at' => now()->subHour(),
    ]);

    $svc = app(InsightCorrelationService::class);
    $c = $svc->correlateForNewFinding($server->fresh());

    expect($c)->toBeArray();
    expect($c['type'])->toBe('firewall_apply');
});

test('ignores firewall event with error meta', function () {
    [$server, $site] = makeServerWithSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    ServerFirewallAuditEvent::query()->create([
        'server_id' => $server->id,
        'event' => ServerFirewallAuditEvent::EVENT_APPLY,
        'meta' => ['error' => 'ufw failed'],
        'created_at' => now(),
    ]);

    $svc = app(InsightCorrelationService::class);
    $c = $svc->correlateForNewFinding($server->fresh());

    expect($c)->toBeArray();
    expect($c['type'])->toBe('site_deployment');
});
