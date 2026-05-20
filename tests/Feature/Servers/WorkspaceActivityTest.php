<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Livewire\Servers\WorkspaceActivity;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class WorkspaceActivityTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['workspace.activity'];

    /**
     * @return array{0: User, 1: Server, 2: Organization}
     */
    private function userServerOrg(): array
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
    private function writeAudit(
        Organization $org,
        ?User $user,
        string $action,
        string $subjectType,
        string $subjectId,
        ?Carbon $createdAt = null,
        ?array $newValues = null,
    ): AuditLog {
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

    public function test_route_renders_for_owning_org(): void
    {
        [$user, $server] = $this->userServerOrg();

        $this->actingAs($user)
            ->get(route('servers.activity', $server))
            ->assertOk()
            ->assertSeeLivewire(WorkspaceActivity::class);
    }

    public function test_route_blocks_users_from_other_orgs(): void
    {
        [, $server] = $this->userServerOrg();

        $stranger = User::factory()->create();
        $strangerOrg = Organization::factory()->create();
        $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
        session(['current_organization_id' => $strangerOrg->id]);

        $this->actingAs($stranger)
            ->get(route('servers.activity', $server))
            ->assertForbidden();
    }

    public function test_feed_includes_server_scoped_audit_events(): void
    {
        [$user, $server, $org] = $this->userServerOrg();

        $this->writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id);
        $this->writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server]);

        $events = $component->instance()->events;

        $this->assertSame(2, $events->total());
        $actions = $events->pluck('action')->all();
        $this->assertContains('server.firewall.rule_added', $actions);
        $this->assertContains('insight.fix_applied', $actions);
    }

    public function test_feed_includes_events_on_sites_owned_by_this_server(): void
    {
        [$user, $server, $org] = $this->userServerOrg();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
        ]);

        $this->writeAudit($org, $user, 'site.deploy.queued', Site::class, $site->id);

        $events = Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server])
            ->instance()->events;

        $this->assertSame(1, $events->total());
        $this->assertSame('site.deploy.queued', $events->first()->action);
    }

    public function test_feed_excludes_events_on_other_servers_in_the_same_org(): void
    {
        [$user, $server, $org] = $this->userServerOrg();

        $otherServer = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '127.0.0.2',
        ]);

        $this->writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $otherServer->id);

        $events = Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server])
            ->instance()->events;

        $this->assertSame(0, $events->total());
    }

    public function test_category_filter_narrows_to_a_single_category(): void
    {
        [$user, $server, $org] = $this->userServerOrg();

        $this->writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id);
        $this->writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id);
        $this->writeAudit($org, $user, 'server.ssh_keys.added', Server::class, $server->id);

        $events = Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server])
            ->call('setCategory', 'firewall')
            ->instance()->events;

        $this->assertSame(1, $events->total());
        $this->assertSame('server.firewall.rule_added', $events->first()->action);
    }

    public function test_range_filter_excludes_events_older_than_the_window(): void
    {
        [$user, $server, $org] = $this->userServerOrg();

        $this->writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id, now()->subHours(2));
        $this->writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id, now()->subDays(40));

        $component = Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server]);

        // Default range is 30d — old event should be excluded.
        $this->assertSame(1, $component->instance()->events->total());

        // Widening to 90d should pull it back in.
        $component->call('setRange', '90d');
        $this->assertSame(2, $component->instance()->events->total());

        // Tightening to 24h should exclude the 2-hour-old event too? No — 2h is within 24h.
        // But narrowing to 24h drops the 40-day-old one, leaving just the recent one.
        $component->call('setRange', '24h');
        $this->assertSame(1, $component->instance()->events->total());
    }

    public function test_categorize_buckets_action_strings_correctly(): void
    {
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
            $this->assertSame(
                $expected,
                WorkspaceActivity::categorize($action),
                "Action {$action} should bucket to {$expected}"
            );
        }
    }

    public function test_trends_aggregates_by_day_and_category(): void
    {
        [$user, $server, $org] = $this->userServerOrg();

        $today = now()->startOfDay()->addHours(10);
        $yesterday = now()->subDay()->startOfDay()->addHours(10);

        $this->writeAudit($org, $user, 'server.firewall.rule_added', Server::class, $server->id, $today);
        $this->writeAudit($org, $user, 'server.firewall.rule_removed', Server::class, $server->id, $today);
        $this->writeAudit($org, $user, 'insight.fix_applied', Server::class, $server->id, $yesterday);

        $trends = Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server])
            ->instance()->trends;

        $todayKey = $today->toDateString();
        $yesterdayKey = $yesterday->toDateString();

        $byDate = collect($trends['buckets'])->keyBy('date');

        $this->assertSame(2, $byDate[$todayKey]['by_category']['firewall'] ?? 0);
        $this->assertSame(1, $byDate[$yesterdayKey]['by_category']['insights'] ?? 0);
        $this->assertSame(2, $trends['totals']['firewall']);
        $this->assertSame(1, $trends['totals']['insights']);
    }

    public function test_clear_filters_resets_to_defaults(): void
    {
        [$user, $server] = $this->userServerOrg();

        Livewire::actingAs($user)
            ->test(WorkspaceActivity::class, ['server' => $server])
            ->call('setCategory', 'firewall')
            ->call('setRange', '7d')
            ->call('setUserId', '01HXYZABCDE1234567890ABCDE')
            ->call('clearFilters')
            ->assertSet('category', '')
            ->assertSet('range', '30d')
            ->assertSet('userId', '');
    }
}
