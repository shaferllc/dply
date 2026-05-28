<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\ServerCertificateInventory;
use App\Services\Servers\ServerDaemonSloPanel;
use App\Services\Servers\ServerDeployPolicyGuard;
use App\Services\Servers\ServerSupervisorStatusParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('supervisor status parser extracts program state', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $program = SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'slug' => 'queue-worker',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/var/www/app/current',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $output = 'dply-sv-'.$program->id.':dply-sv-'.$program->id.'_00   RUNNING   pid 123, uptime 0:15:22';
    $rows = app(ServerSupervisorStatusParser::class)->parseForServer($server->fresh(), $output);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['state'])->toBe('RUNNING')
        ->and($rows[0]['healthy'])->toBeTrue()
        ->and($rows[0]['uptime'])->toBe('0:15:22');
});

test('daemon slo panel flags unhealthy supervisor snapshot', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'supervisor_health' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'ok' => false,
                'summary' => 'Some managed programs need attention',
                'config_drift' => false,
                'detail' => '',
            ],
        ],
    ]);

    $report = app(ServerDaemonSloPanel::class)->forServer($server);

    expect($report['overall'])->toBe('critical')
        ->and($report['alert_count'])->toBeGreaterThan(0);
});

test('cert inventory lists expiring certificates', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => 'vm']]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id]);

    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'status' => SiteCertificate::STATUS_ACTIVE,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => ['app.example.com'],
        'expires_at' => now()->addDays(5),
    ]);

    $report = app(ServerCertificateInventory::class)->forServer($server);

    expect($report['summary']['expiring'])->toBe(1)
        ->and($report['overall'])->toBeIn(['warning', 'critical']);
});

test('deploy policy guard blocks during weekend freeze preset', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-29 18:00:00', 'UTC')); // Friday

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
                'message' => 'Weekend freeze',
                'deny_rules' => app(ServerDeployPolicyGuard::class)->weekendFreezePreset(),
            ],
        ],
    ]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id]);

    $decision = app(ServerDeployPolicyGuard::class)->evaluate($site);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['reason'])->toBe('Weekend freeze');

    Carbon::setTestNow();
});
