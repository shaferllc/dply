<?php

namespace Tests\Feature;

use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Livewire\Servers\WorkspaceManage;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\WebserverSwitchPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the WebserverSwitchPreflight planner and the WorkspaceManage flow that
 * opens the cascade modal, validates the operator's selection, and dispatches
 * SwitchServerWebserverJob. The job's per-stage remote SSH work is stubbed for
 * v1 — these tests cover the orchestration shape end-to-end.
 */
class WebserverSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);
        session(['current_organization_id' => $org->id]);

        return $user->fresh();
    }

    private function makeServer(User $user, array $metaOverrides = []): Server
    {
        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'meta' => array_merge([
                'webserver' => 'nginx',
                'manage_units' => [['unit' => 'nginx', 'active_state' => 'active']],
            ], $metaOverrides),
        ]);
    }

    public function test_planner_blocks_traefik_when_php_sites_present(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'php',
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'traefik');

        $this->assertNotNull($plan['blocker']);
        $this->assertSame('traefik_needs_static', $plan['blocker']['key']);
    }

    public function test_planner_blocks_openlitespeed_when_php_sites_present(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'php',
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'openlitespeed');

        $this->assertNotNull($plan['blocker']);
        $this->assertSame('ols_needs_lsphp', $plan['blocker']['key']);
    }

    public function test_planner_blocks_ols_for_static_only_server_pending_v11_provisioning(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'static',
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'openlitespeed');

        $this->assertNotNull($plan['blocker']);
        $this->assertSame('openlitespeed_provisioning_not_wired', $plan['blocker']['key']);
    }

    public function test_planner_blocks_traefik_for_static_only_server_pending_v11_provisioning(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'static',
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'traefik');

        $this->assertNotNull($plan['blocker']);
        $this->assertSame('traefik_provisioning_not_wired', $plan['blocker']['key']);
    }

    public function test_planner_blocks_switching_to_same_webserver(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'nginx');

        $this->assertNotNull($plan['blocker']);
        $this->assertSame('same_target', $plan['blocker']['key']);
    }

    public function test_planner_passes_for_nginx_to_caddy_with_php_sites(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'php',
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

        $this->assertNull($plan['blocker']);
        $this->assertSame(1, $plan['sites_affected']);
        // Caddy + PHP via FPM is supported — auto cascades, no blocker.
        $this->assertGreaterThanOrEqual(5, count($plan['auto']));
    }

    public function test_planner_surfaces_custom_config_drift(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'name' => 'edited-site',
            'runtime' => 'static',
        ]);
        \App\Models\SiteWebserverConfigProfile::query()->create([
            'site_id' => $site->id,
            'webserver' => 'nginx',
            'mode' => \App\Models\SiteWebserverConfigProfile::MODE_LAYERED,
            'main_snippet_body' => "    add_header X-Custom \"yes\";\n",
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

        $this->assertCount(1, $plan['drift_sites']);
        $this->assertSame((string) $site->id, $plan['drift_sites'][0]['id']);
        $this->assertSame('layered_customizations', $plan['drift_sites'][0]['reason']);
        // Manual list now leads with the drift warning.
        $this->assertStringContainsString('edited-site', $plan['manual'][0]);
    }

    public function test_planner_surfaces_full_override_drift(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'name' => 'fully-overridden',
            'runtime' => 'static',
        ]);
        \App\Models\SiteWebserverConfigProfile::query()->create([
            'site_id' => $site->id,
            'webserver' => 'nginx',
            'mode' => \App\Models\SiteWebserverConfigProfile::MODE_FULL_OVERRIDE,
            'full_override_body' => "server { listen 80; server_name foo; }",
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

        $this->assertCount(1, $plan['drift_sites']);
        $this->assertSame('full_override', $plan['drift_sites'][0]['reason']);
    }

    public function test_planner_omits_drift_when_no_customizations(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'static',
        ]);
        \App\Models\SiteWebserverConfigProfile::query()->create([
            'site_id' => $site->id,
            'webserver' => 'nginx',
            'mode' => \App\Models\SiteWebserverConfigProfile::MODE_LAYERED,
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

        $this->assertSame([], $plan['drift_sites']);
    }

    public function test_planner_offers_tls_optin_for_caddy_with_tls_sites(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'static',
        ]);
        \App\Models\SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => 'customer',
            'provider_type' => 'letsencrypt',
            'challenge_type' => 'http',
            'domains_json' => ['example.com'],
            'status' => 'active',
        ]);

        $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

        $optInKeys = array_column($plan['optIn'], 'key');
        $this->assertContains('tls_to_caddy', $optInKeys);
    }

    public function test_workspace_manage_opens_switch_modal(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'caddy');

        $this->assertNotNull($component->get('switch_plan'));
        $this->assertSame('caddy', $component->get('switch_plan')['to']);
    }

    public function test_workspace_manage_rejects_blocked_target(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'runtime' => 'php',
        ]);

        // Modal opens, but the plan has a blocker. The button in the modal
        // is hidden by the blade conditional; confirmSwitchWebserver()'s
        // defensive check refuses to dispatch.
        Queue::fake();
        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'openlitespeed')
            ->call('confirmSwitchWebserver');

        Queue::assertNotPushed(SwitchServerWebserverJob::class);
    }

    public function test_workspace_manage_dispatches_job_on_confirm(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'caddy')
            ->set('switch_tls_to_caddy', true)
            ->call('confirmSwitchWebserver')
            ->assertSet('switch_plan', null);

        Queue::assertPushed(SwitchServerWebserverJob::class, function (SwitchServerWebserverJob $job) use ($server) {
            return $job->serverId === $server->id
                && $job->target === 'caddy'
                && $job->tlsToCaddy === true;
        });
    }

    public function test_confirm_seeds_queued_console_action_for_banner(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'caddy')
            ->call('confirmSwitchWebserver');

        // The banner-static partial queries for a non-dismissed ConsoleAction
        // on this server with kind=webserver_switch. Without the seed-on-dispatch
        // step, the row only existed after the worker picked the job up, leaving
        // the operator staring at a button that "did nothing." This locks it in.
        $action = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();

        $this->assertNotNull($action);
        $this->assertSame(ConsoleAction::STATUS_QUEUED, $action->status);
    }

    public function test_workspace_manage_refuses_concurrent_switch(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        // Simulate an in-flight switch by creating a ConsoleAction row.
        ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'webserver_switch',
            'status' => ConsoleAction::STATUS_RUNNING,
            'output' => ['v' => 1, 'lines' => []],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'caddy')
            ->assertSet('switch_plan', null);  // refused before opening
    }

    public function test_cancel_clears_switch_plan(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'caddy')
            ->call('cancelSwitchWebserver')
            ->assertSet('switch_plan', null)
            ->assertSet('switch_tls_to_caddy', false);
    }

    public function test_top_level_webserver_workspace_renders_picker_grid(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        $this->actingAs($user)
            ->get(route('servers.webserver', $server))
            ->assertOk()
            ->assertSee('Webserver')
            ->assertSee('Switch to Caddy')
            ->assertSee('Switch to Apache');
    }

    public function test_legacy_manage_web_redirects_to_top_level_webserver(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        $this->actingAs($user)
            ->get(route('servers.manage', ['server' => $server, 'section' => 'web']))
            ->assertRedirect(route('servers.webserver', $server));
    }

    public function test_recent_switches_audit_renders_on_web_tab(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'action' => ServerWebserverAuditEvent::ACTION_SWITCHED,
            'risk' => \App\Services\RemoteCli\RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => 'Switched nginx → caddy',
            'payload' => [
                'from' => 'nginx',
                'to' => 'caddy',
                'sites_affected' => 3,
                'tls_opt_in' => true,
                'duration_ms' => 4200,
            ],
            'result_status' => ServerWebserverAuditEvent::RESULT_SUCCESS,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->assertSee('Switch history')
            ->assertSee('nginx')
            ->assertSee('caddy')
            ->assertSee('TLS handover')
            ->assertSee('3 sites');
    }

    public function test_recent_switches_audit_hidden_when_empty(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->assertDontSee('Switch history');
    }

    public function test_job_records_audit_event_on_success(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        // Run the job synchronously with the SSH-bearing stages no-op'd. The
        // orchestration shape (preflight → audit → meta update) is what we're
        // exercising here; the actual SSH stages have their own tests once the
        // executor service lands.
        $job = new class(serverId: $server->id, target: 'caddy', tlsToCaddy: false, userId: $user->id) extends SwitchServerWebserverJob {
            protected function executeStageInstall(\App\Models\Server $server): void {}
            protected function executeStageProvision(\App\Models\Server $server, array $preflight): void {}
            protected function executeStageValidate(\App\Models\Server $server): void {}
            protected function executeStageCutover(\App\Models\Server $server, string $from): void {}
            protected function executeStageDisableOld(\App\Models\Server $server, string $from): void {}
        };
        $job->handle();

        $audit = ServerWebserverAuditEvent::query()
            ->where('server_id', $server->id)
            ->where('action', ServerWebserverAuditEvent::ACTION_SWITCHED)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('nginx', $audit->payload['from']);
        $this->assertSame('caddy', $audit->payload['to']);
        $this->assertSame('caddy', $server->fresh()->meta['webserver']);
    }

    public function test_confirm_persists_from_and_to_in_console_action_meta(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('openSwitchWebserver', 'caddy')
            ->call('confirmSwitchWebserver');

        $action = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();

        $this->assertNotNull($action);
        $this->assertSame('nginx', $action->output['meta']['from'] ?? null);
        $this->assertSame('caddy', $action->output['meta']['to'] ?? null);
    }

    public function test_stop_and_revert_dispatches_job_and_dismisses_failed_row(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        // Simulate the in-flight switch: a queued ConsoleAction with from/to in
        // its output meta, as confirmSwitchWebserver() would have seeded.
        $row = ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'webserver_switch',
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now()->subMinutes(15),  // stale
            'label' => 'Switching webserver: nginx → caddy …',
            'output' => [
                'v' => 1,
                'meta' => ['from' => 'nginx', 'to' => 'caddy'],
                'lines' => [],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('stopAndRevertWebserverSwitch', (string) $row->id);

        $row->refresh();
        $this->assertSame(ConsoleAction::STATUS_FAILED, $row->status);
        $this->assertNotNull($row->dismissed_at);

        Queue::assertPushed(RevertServerWebserverSwitchJob::class, function (RevertServerWebserverSwitchJob $job) use ($server) {
            return $job->serverId === $server->id
                && $job->target === 'caddy'
                && $job->from === 'nginx';
        });

        // A new seeded webserver_switch row should be present for the banner.
        $banner = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();
        $this->assertNotNull($banner);
        $this->assertSame('caddy', $banner->output['meta']['from'] ?? null);
        $this->assertSame('nginx', $banner->output['meta']['to'] ?? null);
    }

    public function test_stop_and_revert_errors_when_no_inflight_row(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        // A completed row is not inflight — stop+revert must refuse.
        $row = ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'webserver_switch',
            'status' => ConsoleAction::STATUS_COMPLETED,
            'label' => 'Switching webserver: nginx → caddy …',
            'output' => ['v' => 1, 'meta' => ['from' => 'nginx', 'to' => 'caddy'], 'lines' => []],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('stopAndRevertWebserverSwitch', (string) $row->id);

        Queue::assertNotPushed(RevertServerWebserverSwitchJob::class);
    }
}
