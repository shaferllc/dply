<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Services\Servers\ServerDeployPolicyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Deploy windows are GA — no feature flag gates enforcement.

test('deploy job is skipped when server deploy window blocks', function (): void {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-05-29 18:00:00', 'UTC'));

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'deploy_policy' => [
                'enabled' => true,
                'timezone' => 'UTC',
                'message' => 'Weekend freeze active',
                'deny_rules' => app(ServerDeployPolicyGuard::class)->weekendFreezePreset(),
            ],
        ],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
    ]);

    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);
    RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

    $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest('id')->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->status)->toBe(SiteDeployment::STATUS_SKIPPED)
        ->and($deployment->skip_reason)->toBe(SiteDeployment::SKIP_REASON_DEPLOY_WINDOW)
        ->and($deployment->skip_rule_summary)->not->toBeNull()
        ->and($deployment->log_output)->toContain('Weekend freeze active');

    Carbon::setTestNow();
});

test('deploy policy report summarizes rules sites and next allowed time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-29 18:00:00', 'UTC'));

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'deploy_policy' => [
                'enabled' => true,
                'timezone' => 'UTC',
                'message' => 'Weekend freeze active',
                'deny_rules' => app(ServerDeployPolicyGuard::class)->weekendFreezePreset(),
            ],
        ],
    ]);

    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'api',
    ]);

    $report = app(ServerDeployPolicyGuard::class)->report($server->fresh());

    expect($report['overall'])->toBe('blocked')
        ->and($report['summary']['rule_count'])->toBe(4)
        ->and($report['summary']['active_rules_now'])->toBeGreaterThan(0)
        ->and($report['summary']['total_sites'])->toBe(1)
        ->and($report['site_rows'])->toHaveCount(1)
        ->and($report['evaluation']['next_allowed_at'])->not->toBeNull();

    Carbon::setTestNow();
});
