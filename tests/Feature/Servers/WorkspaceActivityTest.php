<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\WorkspaceActivityTest;

use App\Livewire\Servers\WorkspaceActivity;
use App\Livewire\Servers\WorkspaceLogs;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Organization}
 */
function userServerOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '127.0.0.1',
    ]);

    return [$user, $server, $org];
}
/**
 * Direct insert so we can pin created_at — the helper would stamp it
 * to "now" and we want to verify date-range behavior at boundaries.
 *
 * `created_at` isn't in AuditLog's $fillable so Eloquent's mass-assign would
 * silently drop the explicit value and stamp `now()` via the model's saving
 * event. Use withoutTimestamps + an explicit assign so the row lands with
 * the timestamp the test demands.
 */
function writeAudit(Organization $org, ?User $user, string $action, string $subjectType, string $subjectId, ?Carbon $createdAt = null, ?array $newValues = null): AuditLog
{
    $createdAt ??= now();

    return AuditLog::withoutTimestamps(function () use ($org, $user, $action, $subjectType, $subjectId, $newValues, $createdAt): AuditLog {
        $row = new AuditLog([
            'organization_id' => $org->id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => null,
            'new_values' => $newValues,
            'ip_address' => '127.0.0.1',
        ]);
        $row->created_at = $createdAt;
        $row->updated_at = $createdAt;
        $row->save();

        return $row;
    });
}
test('logs activity tab renders the nested timeline for owning org', function () {
    // Activity is now a tab on the Logs page; the standalone route was retired.
    [$user, $server] = userServerOrg();

    $this->actingAs($user)
        ->get(route('servers.logs', ['server' => $server, 'tab' => 'activity']))
        ->assertOk()
        ->assertSeeLivewire(WorkspaceLogs::class)
        ->assertSeeLivewire(WorkspaceActivity::class);
});
test('logs activity tab blocks users from other orgs', function () {
    [, $server] = userServerOrg();

    $stranger = User::factory()->create();
    $strangerOrg = Organization::factory()->create();
    $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
    session(['current_organization_id' => $strangerOrg->id]);

    $this->actingAs($stranger)
        ->get(route('servers.logs', ['server' => $server, 'tab' => 'activity']))
        ->assertForbidden();
});
test('feed includes server scoped audit events', function () {
    [$user, $server, $org] = userServerOrg();

    writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id);
    writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server]);

    $events = $component->instance()->events;

    expect($events->total())->toBe(2);
    $actions = $events->pluck('action')->all();
    expect($actions)->toContain('server.firewall.rule_added');
    expect($actions)->toContain('insight.fix_applied');
});
test('feed includes events on sites owned by this server', function () {
    [$user, $server, $org] = userServerOrg();

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
    ]);

    writeAudit($org, $user, 'site.deploy.queued', Site::class, $site->id);

    $events = Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server])
        ->instance()->events;

    expect($events->total())->toBe(1);
    expect($events->first()->action)->toBe('site.deploy.queued');
});
test('feed excludes events on other servers in the same org', function () {
    [$user, $server, $org] = userServerOrg();

    $otherServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '127.0.0.2',
    ]);

    writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $otherServer->id);

    $events = Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server])
        ->instance()->events;

    expect($events->total())->toBe(0);
});
test('category filter narrows to a single category', function () {
    [$user, $server, $org] = userServerOrg();

    writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id);
    writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id);
    writeAudit($org, $user, 'server.ssh_keys.added', Server::class, $server->id);

    $events = Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server])
        ->call('setCategory', 'firewall')
        ->instance()->events;

    expect($events->total())->toBe(1);
    expect($events->first()->action)->toBe('server.firewall.rule_added');
});
test('range filter excludes events older than the window', function () {
    [$user, $server, $org] = userServerOrg();

    writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id, now()->subHours(2));
    writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id, now()->subDays(40));

    $component = Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server]);

    // Default range is 30d — old event should be excluded.
    expect($component->instance()->events->total())->toBe(1);

    // Widening to 90d should pull it back in.
    $component->call('setRange', '90d');
    expect($component->instance()->events->total())->toBe(2);

    // Tightening to 24h should exclude the 2-hour-old event too? No — 2h is within 24h.
    // But narrowing to 24h drops the 40-day-old one, leaving just the recent one.
    $component->call('setRange', '24h');
    expect($component->instance()->events->total())->toBe(1);
});
test('categorize buckets action strings correctly', function () {
    $cases = [
        'insight.fix_applied' => 'insights',
        'server.firewall.rule_added' => 'firewall',
        'server.ssh_keys.added' => 'ssh',
        'server.caches.installed' => 'caches',
        'server.databases.created' => 'databases',
        'site.deploy.queued' => 'deploys',
        'project.deploy.success' => 'deploys',
        'server.created' => 'server',
        'site.webserver_config.applied' => 'site',
        'team.created' => 'other',
    ];

    foreach ($cases as $action => $expected) {
        expect(WorkspaceActivity::categorize($action))->toBe($expected, "Action {$action} should bucket to {$expected}");
    }
});
test('trends aggregates by day and category', function () {
    [$user, $server, $org] = userServerOrg();

    $today = now()->startOfDay()->addHours(10);
    $yesterday = now()->subDay()->startOfDay()->addHours(10);

    writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id, $today);
    writeAudit($org, $user, 'server.firewall.rule_removed', Server::class, $server->id, $today);
    writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id, $yesterday);

    $trends = Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server])
        ->instance()->trends;

    $todayKey = $today->toDateString();
    $yesterdayKey = $yesterday->toDateString();

    $byDate = collect($trends['buckets'])->keyBy('date');

    expect($byDate[$todayKey]['by_category']['firewall'] ?? 0)->toBe(2);
    expect($byDate[$yesterdayKey]['by_category']['insights'] ?? 0)->toBe(1);
    expect($trends['totals']['firewall'])->toBe(2);
    expect($trends['totals']['insights'])->toBe(1);
});
test('clear filters resets to defaults', function () {
    [$user, $server] = userServerOrg();

    Livewire::actingAs($user)
        ->test(WorkspaceActivity::class, ['server' => $server])
        ->call('setCategory', 'firewall')
        ->call('setRange', '7d')
        ->call('setUserId', '01HXYZABCDE1234567890ABCDE')
        ->call('clearFilters')
        ->assertSet('category', '')
        ->assertSet('range', '30d')
        ->assertSet('userId', '');
});
