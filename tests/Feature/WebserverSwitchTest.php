<?php

namespace Tests\Feature\WebserverSwitchTest;

use App\Jobs\ExecuteSiteCertificateJob;
use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SitePreviewDomain;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Services\Servers\WebserverSwitchPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_workspace.webserver_coming_soon' => []]);
});

function makeUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

function makeServer(User $user, array $metaOverrides = []): Server
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

test('planner treats traefik as unknown target', function () {
    // Traefik moved out of the webserver picker into the dedicated
    // Edge Proxy surface (AddEdgeProxyJob). Preflight should reject
    // it as a switch target so legacy callers see a clear error
    // instead of silently failing mid-flight.
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'php',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'traefik');

    expect($plan['blocker'])->not->toBeNull();
    expect($plan['blocker']['key'])->toBe('unknown_target');
});

test('planner allows openlitespeed for php sites', function () {
    // OLS switch flow now installs lsphpXX matched to site PHP versions,
    // writes per-vhost vhconf.conf files, and renders a dply-owned
    // httpd_config.conf — preflight no longer blocks.
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'php',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'openlitespeed');

    expect($plan['blocker'])->toBeNull();
});

test('planner allows openlitespeed for static only server', function () {
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'static',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'openlitespeed');

    expect($plan['blocker'])->toBeNull();
});

test('planner treats haproxy as unknown target', function () {
    // HAProxy is also an edge proxy — same story as Traefik.
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'static',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'haproxy');

    expect($plan['blocker'])->not->toBeNull();
    expect($plan['blocker']['key'])->toBe('unknown_target');
});

test('planner blocks switching to same webserver', function () {
    $user = makeUser();
    $server = makeServer($user);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'nginx');

    expect($plan['blocker'])->not->toBeNull();
    expect($plan['blocker']['key'])->toBe('same_target');
});

test('planner passes for nginx to caddy with php sites', function () {
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'php',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

    expect($plan['blocker'])->toBeNull();
    expect($plan['sites_affected'])->toBe(1);

    // Caddy + PHP via FPM is supported — auto cascades, no blocker.
    expect(count($plan['auto']))->toBeGreaterThanOrEqual(5);
});

test('planner surfaces custom config drift', function () {
    $user = makeUser();
    $server = makeServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'edited-site',
        'runtime' => 'static',
    ]);
    SiteWebserverConfigProfile::query()->create([
        'site_id' => $site->id,
        'webserver' => 'nginx',
        'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
        'main_snippet_body' => "    add_header X-Custom \"yes\";\n",
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

    expect($plan['drift_sites'])->toHaveCount(1);
    expect($plan['drift_sites'][0]['id'])->toBe((string) $site->id);
    expect($plan['drift_sites'][0]['reason'])->toBe('layered_customizations');

    // Manual list now leads with the drift warning.
    $this->assertStringContainsString('edited-site', $plan['manual'][0]);
});

test('planner surfaces full override drift', function () {
    $user = makeUser();
    $server = makeServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'fully-overridden',
        'runtime' => 'static',
    ]);
    SiteWebserverConfigProfile::query()->create([
        'site_id' => $site->id,
        'webserver' => 'nginx',
        'mode' => SiteWebserverConfigProfile::MODE_FULL_OVERRIDE,
        'full_override_body' => 'server { listen 80; server_name foo; }',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

    expect($plan['drift_sites'])->toHaveCount(1);
    expect($plan['drift_sites'][0]['reason'])->toBe('full_override');
});

test('planner omits drift when no customizations', function () {
    $user = makeUser();
    $server = makeServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'static',
    ]);
    SiteWebserverConfigProfile::query()->create([
        'site_id' => $site->id,
        'webserver' => 'nginx',
        'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

    expect($plan['drift_sites'])->toBe([]);
});

test('planner offers tls optin for caddy with tls sites', function () {
    $user = makeUser();
    $server = makeServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'static',
    ]);
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => 'customer',
        'provider_type' => 'letsencrypt',
        'challenge_type' => 'http',
        'domains_json' => ['example.com'],
        'status' => 'active',
    ]);

    $plan = app(WebserverSwitchPreflight::class)->plan($server, 'caddy');

    $optInKeys = array_column($plan['optIn'], 'key');
    expect($optInKeys)->toContain('tls_to_caddy');
});

test('workspace manage opens switch modal', function () {
    $user = makeUser();
    $server = makeServer($user);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->assertSet('switch_plan', null)
        ->assertSet('switch_preflight_target', 'caddy');

    $component->call('loadSwitchPlan');

    expect($component->get('switch_plan'))->not->toBeNull();
    expect($component->get('switch_plan')['to'])->toBe('caddy');
    expect($component->get('switch_preflight_target'))->toBeNull();
});

test('workspace manage rejects blocked target', function () {
    $user = makeUser();
    $server = makeServer($user);

    // Stand up a running Varnish row — Varnish owns :8080 and the switch flow
    // stages on :8080 too, so {@see WebserverSwitchPreflight::detectBlocker}
    // hard-rejects any target while it's running. Operator must uninstall
    // Varnish before switching the webserver.
    ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'varnish',
        'name' => 'default',
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 80,
    ]);

    // Modal opens, but the plan has a blocker. The button in the modal
    // is hidden by the blade conditional; confirmSwitchWebserver()'s
    // defensive check refuses to dispatch.
    Queue::fake();
    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->call('loadSwitchPlan')
        ->call('confirmSwitchWebserver');

    Queue::assertNotPushed(SwitchServerWebserverJob::class);
});

test('workspace manage dispatches job on confirm', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->call('loadSwitchPlan')
        ->set('switch_tls_to_caddy', true)
        ->call('confirmSwitchWebserver')
        ->assertSet('switch_plan', null);

    Queue::assertPushed(SwitchServerWebserverJob::class, function (SwitchServerWebserverJob $job) use ($server) {
        return $job->serverId === $server->id
            && $job->target === 'caddy'
            && $job->tlsToCaddy === true;
    });
});

test('confirm seeds queued console action for banner', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->call('loadSwitchPlan')
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

    expect($action)->not->toBeNull();
    expect($action->status)->toBe(ConsoleAction::STATUS_QUEUED);
});

test('confirm shows switching in progress after modal confirm', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'openlitespeed')
        ->call('loadSwitchPlan')
        ->call('confirmSwitchWebserver')
        ->assertSee(__('Switching webserver: :from → :to …', ['from' => 'nginx', 'to' => 'openlitespeed']));
});

test('workspace manage refuses concurrent switch', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

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
        ->assertSet('switch_plan', null)
        ->assertSet('switch_preflight_target', null);
    // refused before opening
});

test('cancel clears switch plan', function () {
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->call('cancelSwitchWebserver')
        ->assertSet('switch_plan', null)
        ->assertSet('switch_preflight_target', null)
        ->assertSet('switch_tls_to_caddy', false);
});

test('top level webserver workspace renders picker grid', function () {
    config(['server_workspace.webserver_coming_soon' => ['caddy', 'apache', 'openlitespeed']]);
    config(['server_workspace.edge_proxy_coming_soon' => ['traefik', 'haproxy']]);

    $user = makeUser();
    $server = makeServer($user);

    $this->actingAs($user)
        ->get(route('servers.webserver', $server).'?tab=change')
        ->assertOk()
        ->assertSee('Webserver')
        ->assertSee('Coming soon')
        ->assertDontSee('Switch to Caddy')
        ->assertDontSee('Switch to Apache');
});

test('workspace rejects coming soon switch target', function () {
    config(['server_workspace.webserver_coming_soon' => ['caddy']]);

    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->assertSet('switch_plan', null);
});

test('legacy manage web redirects to top level webserver', function () {
    $user = makeUser();
    $server = makeServer($user);

    $this->actingAs($user)
        ->get(route('servers.manage', ['server' => $server, 'section' => 'web']))
        ->assertRedirect(route('servers.webserver', $server));
});

test('recent switches audit renders on web tab', function () {
    $user = makeUser();
    $server = makeServer($user);

    ServerWebserverAuditEvent::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'action' => ServerWebserverAuditEvent::ACTION_SWITCHED,
        'risk' => RiskLevel::MutatingRecoverable->value,
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
        ->call('setWorkspaceTab', 'advanced')
        ->assertSee('Switch history')
        ->assertSee('nginx')
        ->assertSee('caddy')
        ->assertSee('TLS handover')
        ->assertSee('3 sites');
});

test('recent switches audit hidden when empty', function () {
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->assertDontSee('Switch history');
});

test('job records audit event on success', function () {
    $user = makeUser();
    $server = makeServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    // Run the job synchronously with the SSH-bearing stages no-op'd. The
    // orchestration shape (preflight → audit → meta update) is what we're
    // exercising here; the actual SSH stages have their own tests once the
    // executor service lands.
    $job = new class(serverId: $server->id, target: 'caddy', tlsToCaddy: false, userId: $user->id) extends SwitchServerWebserverJob
    {
        // Production `executeStageInstall` takes an additional ConsoleEmitter for streaming
        // apt output into the banner; the no-op test override matches that signature so the
        // anonymous-class declaration stays binary-compatible with the parent.
        protected function executeStageInstall(Server $server, ConsoleEmitter $emitter): void {}

        protected function executeStageProvision(Server $server, array $preflight): void {}

        protected function executeStageValidate(Server $server): void {}

        protected function executeStageCutover(Server $server, string $from): void {}

        protected function executeStageDisableOld(Server $server, string $from): void {}
    };
    $job->handle();

    $audit = ServerWebserverAuditEvent::query()
        ->where('server_id', $server->id)
        ->where('action', ServerWebserverAuditEvent::ACTION_SWITCHED)
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->payload['from'])->toBe('nginx');
    expect($audit->payload['to'])->toBe('caddy');
    expect($server->fresh()->meta['webserver'])->toBe('caddy');
    expect($site->fresh()->status)->toBe(Site::STATUS_CADDY_ACTIVE);
});

test('job requeues preview ssl after switch away from caddy', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, ['webserver' => 'caddy']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'status' => Site::STATUS_CADDY_ACTIVE,
    ]);
    $preview = SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'preview.example.test',
        'auto_ssl' => true,
        'is_primary' => true,
        'managed_by_dply' => true,
    ]);
    $certificate = SiteCertificate::query()->create([
        'site_id' => $site->id,
        'preview_domain_id' => $preview->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => [$preview->hostname],
        'status' => SiteCertificate::STATUS_FAILED,
    ]);

    $job = new class(serverId: $server->id, target: 'apache', tlsToCaddy: false, userId: $user->id) extends SwitchServerWebserverJob
    {
        protected function executeStageInstall(Server $server, ConsoleEmitter $emitter): void {}

        protected function executeStageProvision(Server $server, array $preflight): void {}

        protected function executeStageValidate(Server $server): void {}

        protected function executeStageCutover(Server $server, string $from): void {}

        protected function executeStageDisableOld(Server $server, string $from): void {}
    };
    $job->handle();

    expect($site->fresh()->status)->toBe(Site::STATUS_APACHE_ACTIVE);
    expect($certificate->fresh()->status)->toBe(SiteCertificate::STATUS_PENDING);
    Queue::assertPushed(ExecuteSiteCertificateJob::class, fn (ExecuteSiteCertificateJob $job) => $job->certificateId === $certificate->id);
});

test('confirm persists from and to in console action meta', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openSwitchWebserver', 'caddy')
        ->call('loadSwitchPlan')
        ->call('confirmSwitchWebserver');

    $action = ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->where('kind', 'webserver_switch')
        ->whereNull('dismissed_at')
        ->first();

    expect($action)->not->toBeNull();
    expect($action->output['meta']['from'] ?? null)->toBe('nginx');
    expect($action->output['meta']['to'] ?? null)->toBe('caddy');
});

test('stop and revert dispatches job and dismisses failed row', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

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
    expect($row->status)->toBe(ConsoleAction::STATUS_FAILED);
    expect($row->dismissed_at)->not->toBeNull();

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
    expect($banner)->not->toBeNull();
    expect($banner->output['meta']['from'] ?? null)->toBe('caddy');
    expect($banner->output['meta']['to'] ?? null)->toBe('nginx');
});

test('cleanup failed switch dispatches revert job', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    $row = ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'webserver_switch',
        'status' => ConsoleAction::STATUS_FAILED,
        'finished_at' => now(),
        'error' => 'Failed to start caddy during cutover',
        'label' => 'Switching webserver: nginx → caddy …',
        'output' => [
            'v' => 1,
            'meta' => ['from' => 'nginx', 'to' => 'caddy'],
            'lines' => [],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('cleanupFailedWebserverSwitch', (string) $row->id);

    $row->refresh();
    expect($row->dismissed_at)->not->toBeNull();

    Queue::assertPushed(RevertServerWebserverSwitchJob::class, function (RevertServerWebserverSwitchJob $job) use ($server) {
        return $job->serverId === $server->id
            && $job->target === 'caddy'
            && $job->from === 'nginx';
    });
});

test('cleanup failed switch errors when row is still in flight', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    $row = ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'webserver_switch',
        'status' => ConsoleAction::STATUS_RUNNING,
        'started_at' => now(),
        'label' => 'Switching webserver: nginx → caddy …',
        'output' => ['v' => 1, 'meta' => ['from' => 'nginx', 'to' => 'caddy'], 'lines' => []],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('cleanupFailedWebserverSwitch', (string) $row->id);

    Queue::assertNotPushed(RevertServerWebserverSwitchJob::class);
});

test('switch job validates caddy as caddy user and restarts on cutover', function () {
    $source = file_get_contents(app_path('Jobs/SwitchServerWebserverJob.php'));

    expect($source)
        ->toContain('CaddyRuntimeOwnership::validateCommand()')
        ->toContain('systemctl enable %1$s 2>/dev/null || true; systemctl restart %1$s');
});

test('stop and revert errors when no inflight row', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

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
});

test('ols installer includes lsphp packages for site php versions', function () {
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'php',
        'runtime_version' => '8.2',
    ]);

    // Static site shouldn't pull in any lsphp packages.
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'static',
    ]);

    $job = new SwitchServerWebserverJob($server->id, $user->id, 'openlitespeed', false);
    $script = invokePrivate($job, 'installerScriptFor', ['openlitespeed', $server]);

    $this->assertStringContainsString('lsphp83', $script);
    $this->assertStringContainsString('lsphp83-common', $script);
    $this->assertStringContainsString('lsphp82', $script);

    // No PHP 8.1 site → no lsphp81 install.
    $this->assertStringNotContainsString('lsphp81', $script);

    // Core install always present.
    $this->assertStringContainsString('openlitespeed', $script);
});

test('ols installer skips lsphp when no php sites', function () {
    $user = makeUser();
    $server = makeServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'runtime' => 'static',
    ]);

    $job = new SwitchServerWebserverJob($server->id, $user->id, 'openlitespeed', false);
    $script = invokePrivate($job, 'installerScriptFor', ['openlitespeed', $server]);

    $this->assertStringNotContainsString('lsphp', $script);
});

test('ols site config path targets vhconf', function () {
    $user = makeUser();
    $server = makeServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'app-one',
        'runtime' => 'php',
    ]);

    $job = new SwitchServerWebserverJob($server->id, $user->id, 'openlitespeed', false);
    $path = invokePrivate($job, 'siteConfigPathFor', [$site, 'openlitespeed']);

    expect($path)->toStartWith('/usr/local/lsws/conf/vhosts/');
    expect($path)->toEndWith('/vhconf.conf');
});

// Note: Traefik + HAProxy switch-flow tests were removed when those
// engines moved to the dedicated Edge Proxy surface
// (AddEdgeProxyJob / RemoveEdgeProxyJob). The relevant new tests live
// in EdgeProxyTest.php.
function invokePrivate(object $obj, string $method, array $args = []): mixed
{
    $ref = new \ReflectionMethod($obj, $method);

    return $ref->invokeArgs($obj, $args);
}
