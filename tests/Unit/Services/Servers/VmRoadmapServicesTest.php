<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Modules\Certificates\Services\ServerCertificateInventory;
use App\Services\Servers\ServerDaemonSloPanel;
use App\Services\Servers\ServerDeployPolicyGuard;
use App\Services\Servers\ServerSecurityDigest;
use App\Services\Servers\ServerSecurityDigestScript;
use App\Services\Servers\ServerSshAccessGraph;
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

test('ssh access graph summarizes authorized keys', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => 'vm']]);

    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'name' => 'Contractor',
        'public_key' => 'ssh-ed25519 AAA test',
        'target_linux_user' => 'dply',
        'review_after' => now()->subDay(),
    ]);

    $report = app(ServerSshAccessGraph::class)->forServer($server);

    expect($report['summary']['total'])->toBe(1)
        ->and($report['summary']['review_overdue'])->toBe(1);
});

test('security digest flags high auth failure count', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'security_digest_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'auth_failed_lines' => 250,
                'fail2ban_active' => 'active',
                'fail2ban_jails' => ['sshd'],
            ],
        ],
    ]);

    $report = app(ServerSecurityDigest::class)->forServer($server);

    expect($report['overall'])->toBe('critical')
        ->and($report['auth']['failed_lines'])->toBe(250)
        ->and($report['summary']['auth_failed_total'])->toBe(250);
});

test('security digest script parses jail stats and hardening fields', function (): void {
    $output = <<<'OUT'
DIGEST_BEGIN
auth_failed_lines=12
auth_invalid_user_lines=5
auth_failed_password_lines=7
auth_failed_recent=3
ufw_active=active
sshd_password_auth=no
sshd_permit_root=prohibit-password
fail2ban_active=active
FAIL2BAN_BEGIN
Status
|- Number of jail:	1
`- Jail list:	sshd
FAIL2BAN_END
JAIL_BEGIN=sshd
Status for the jail: sshd
|- Filter
|  |- Currently failed:	2
|  |- Total failed:	40
|  `- File list:	/var/log/auth.log
`- Actions
   |- Currently banned:	1
   |- Total banned:	9
   `- Banned IP list:	203.0.113.10
JAIL_END
DIGEST_END
OUT;

    $meta = app(ServerSecurityDigestScript::class)->parse($output, ['host_kind' => 'vm']);
    $snapshot = $meta['security_digest_snapshot'];

    expect($snapshot['auth_failed_lines'])->toBe(12)
        ->and($snapshot['auth_invalid_user_lines'])->toBe(5)
        ->and($snapshot['auth_failed_recent'])->toBe(3)
        ->and($snapshot['ufw_active'])->toBe('active')
        ->and($snapshot['sshd_password_auth'])->toBe('no')
        ->and($snapshot['fail2ban_jail_rows'][0]['name'])->toBe('sshd')
        ->and($snapshot['fail2ban_jail_rows'][0]['currently_banned'])->toBe(1)
        ->and($snapshot['fail2ban_jail_rows'][0]['banned_ips'])->toBe(['203.0.113.10']);
});

test('security digest warns on password authentication enabled', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'security_digest_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'auth_failed_lines' => 2,
                'fail2ban_active' => 'active',
                'fail2ban_jails' => ['sshd'],
                'fail2ban_jail_rows' => [],
                'sshd_password_auth' => 'yes',
                'ufw_active' => 'active',
            ],
        ],
    ]);

    $report = app(ServerSecurityDigest::class)->forServer($server);

    expect(collect($report['alerts'])->pluck('title')->contains(__('Password authentication enabled')))->toBeTrue();
});
