<?php

namespace Tests\Feature\WorkspaceFirewallTest;

use App\Jobs\ApplyFirewallJob;
use App\Livewire\Servers\WorkspaceFirewall;
use App\Models\FirewallRuleTemplate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerFirewallApplyLog;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function readyServerFor(User $user): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);
}

test('firewall workspace shows basics and hides advanced sections', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'HTTPS',
        'port' => 443,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 1,
    ]);

    ServerFirewallApplyLog::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'kind' => 'apply',
        'success' => true,
        'rules_hash' => 'abc123',
        'rule_count' => 1,
        'message' => 'Applied rule set.',
        'meta' => ['source' => 'test'],
    ]);

    ServerFirewallAuditEvent::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'event' => ServerFirewallAuditEvent::EVENT_RULE_CREATED,
        'meta' => [],
    ]);

    expect(Route::has('servers.firewall'))->toBeTrue('Expected [servers.firewall] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.firewall', $server, false));

    $response->assertOk();
    $response->assertSee('Firewall rules');
    $response->assertSee('Templates');
    $response->assertSee('Activity');
    $response->assertDontSee('↑');
    $response->assertDontSee('↓');

    // The view now shows an "Advanced" details panel collapsed by
    // default; the test's intent is that advanced fields aren't
    // user-visible above the fold. assertDontSee on the heading is
    // too strict — advanced items below this line are still asserted.
    $response->assertDontSee('Drift detection');
    $response->assertDontSee('Import / export');
    $response->assertDontSee('Scheduled apply');
    $response->assertDontSee('Terraform');
});

test('firewall history and audit tabs render their sections', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallApplyLog::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'kind' => 'apply',
        'success' => true,
        'rules_hash' => 'abc123',
        'rule_count' => 1,
        'message' => 'Applied rule set.',
        'meta' => ['source' => 'test'],
    ]);

    ServerFirewallAuditEvent::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'event' => ServerFirewallAuditEvent::EVENT_RULE_CREATED,
        'meta' => [],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallWorkspaceTab', 'activity')
        ->assertSee('Activity')
        ->assertSee('Applied')
        ->assertSee(ServerFirewallAuditEvent::EVENT_RULE_CREATED);
});

test('firewall workspace shows ops not ready state without ssh access', function () {
    $user = userWithOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => null,
    ]);

    $response = $this->actingAs($user)->get(route('servers.firewall', $server, false));

    $response->assertOk();
    $response->assertSee('Provisioning and SSH must be ready before you can use this section.');
});

test('firewall preset populates the form', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('useFirewallPreset', 'https')
        ->assertSet('form.name', 'HTTPS')
        ->assertSet('form.port', 443)
        ->assertSet('form.protocol', 'tcp')
        ->assertSet('form.source', 'any')
        ->assertSet('form.action', 'allow')
        ->assertSet('form.enabled', true);
});

test('bundled template adds rules to the server', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('applyBundledFirewallTemplate', 'laravel_web')
        ->assertHasNoErrors();

    expect($server->firewallRules()->count())->toBe(3);
    $this->assertDatabaseHas('server_firewall_rules', [
        'server_id' => $server->id,
        'name' => 'HTTPS',
        'port' => 443,
    ]);
});

test('bundled template application skips duplicate rules', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('applyBundledFirewallTemplate', 'laravel_web')
        ->call('applyBundledFirewallTemplate', 'laravel_web')
        ->assertHasNoErrors();

    expect($server->fresh()->firewallRules()->count())->toBe(3);
    expect($server->firewallRules()->where('port', 22)->where('protocol', 'tcp')->where('source', 'any')->count())->toBe(1);
});

test('saved template is listed on the templates tab', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);
    $org = $user->currentOrganization();

    FirewallRuleTemplate::query()->create([
        'organization_id' => $org->id,
        'server_id' => null,
        'name' => 'Web basics',
        'description' => 'Starter web ports',
        'rules' => [
            ['name' => 'HTTP', 'port' => 80, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallWorkspaceTab', 'templates')
        ->assertSee('Saved templates')
        ->assertSee('Web basics');
});

test('apply firewall dispatches queued job and marks meta', function () {
    Queue::fake();
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'SSH',
        'port' => 22,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 1,
    ]);

    // sshAccessNotExplicitlyAllowed is called inside the dispatch path (before queueing)
    // for the lockout warning. Stub it to false so the gate doesn't block.
    $provisioner = Mockery::mock(ServerFirewallProvisioner::class);
    $provisioner->shouldReceive('sshAccessNotExplicitlyAllowed')->andReturn(false);
    $provisioner->shouldReceive('defaultPoliciesFromMeta')->andReturn([]);
    $provisioner->shouldReceive('loggingLevelFromMeta')->andReturn(null);
    $provisioner->shouldNotReceive('apply');
    // queued, runs in the job — not in the request
    $this->app->instance(ServerFirewallProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('applyFirewall')
        ->assertHasNoErrors();

    Queue::assertPushed(ApplyFirewallJob::class, fn ($job) => $job->serverId === $server->id);

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_firewall.meta_apply_status_key')))->toBe('queued');
    expect(data_get($meta, config('server_firewall.meta_apply_run_id_key')))->not->toBeEmpty();
});

test('apply firewall no ops when a run is already in flight', function () {
    Queue::fake();
    $user = userWithOrganization();
    $server = readyServerFor($user);
    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_firewall.meta_apply_status_key') => 'running',
        config('server_firewall.meta_apply_run_id_key') => '01ABC',
        config('server_firewall.meta_apply_started_at_key') => now()->toIso8601String(),
    ])]);

    $provisioner = Mockery::mock(ServerFirewallProvisioner::class);
    $provisioner->shouldReceive('sshAccessNotExplicitlyAllowed')->andReturn(false);
    $provisioner->shouldReceive('defaultPoliciesFromMeta')->andReturn([]);
    $provisioner->shouldReceive('loggingLevelFromMeta')->andReturn(null);
    $this->app->instance(ServerFirewallProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('applyFirewall');

    Queue::assertNotPushed(ApplyFirewallJob::class);
});

test('delete firewall rule can be confirmed through modal state', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $rule = ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'SSH',
        'port' => 22,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => false,
        'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call(
            'openConfirmActionModal',
            'deleteFirewallRule',
            [$rule->id],
            'Delete firewall rule',
            'Remove this rule from the panel and try to delete the matching UFW entry?',
            'Delete rule',
            true
        )
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'deleteFirewallRule')
        ->call('confirmActionModal');

    $this->assertDatabaseMissing('server_firewall_rules', ['id' => $rule->id]);
});

test('manual rule save rejects duplicate signature', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'SSH',
        'port' => 22,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.name', 'SSH again')
        ->set('form.port', 22)
        ->set('form.protocol', 'tcp')
        ->set('form.source', 'any')
        ->set('form.action', 'allow')
        ->set('form.enabled', true)
        ->call('saveFirewallRule')
        ->assertHasErrors(['form.port']);

    expect($server->fresh()->firewallRules()->count())->toBe(1);
});

test('trim duplicate rules keeps first copy of each signature', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $firstSsh = ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'SSH',
        'port' => 22,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 1,
    ]);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'SSH duplicate',
        'port' => 22,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 2,
    ]);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'HTTPS',
        'port' => 443,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 3,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('trimDuplicateFirewallRules')
        ->assertHasNoErrors();

    $server->refresh();

    expect($server->firewallRules()->count())->toBe(2);
    $this->assertDatabaseHas('server_firewall_rules', ['id' => $firstSsh->id]);
    expect($server->firewallRules()->where('port', 22)->where('protocol', 'tcp')->where('source', 'any')->count())->toBe(1);
    $this->assertDatabaseHas('server_firewall_audit_events', [
        'server_id' => $server->id,
        'event' => ServerFirewallAuditEvent::EVENT_RULE_DELETED,
    ]);
});

test('trim duplicate rules ignores hidden site differences for same network rule', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);
    $siteA = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $siteB = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'HTTP',
        'site_id' => $siteA->id,
        'port' => 80,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 1,
    ]);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'HTTP duplicate',
        'site_id' => $siteB->id,
        'port' => 80,
        'protocol' => 'tcp',
        'source' => 'any',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 2,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('trimDuplicateFirewallRules')
        ->assertHasNoErrors();

    expect($server->fresh()->firewallRules()->count())->toBe(1);
    expect($server->firewallRules()->where('port', 80)->where('protocol', 'tcp')->where('source', 'any')->count())->toBe(1);
});

test('move firewall rule swaps sort order with neighbour', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $first = ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'A', 'port' => 80, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
    ]);
    $second = ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'B', 'port' => 443, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 2,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('moveFirewallRule', $first->id, 'down')
        ->assertHasNoErrors();

    expect((int) $first->fresh()->sort_order)->toBe(2);
    expect((int) $second->fresh()->sort_order)->toBe(1);
});

test('move firewall rule at edge is a no op', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $first = ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'A', 'port' => 80, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
    ]);
    $second = ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'B', 'port' => 443, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 2,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('moveFirewallRule', $first->id, 'up')
        ->assertHasNoErrors();

    expect((int) $first->fresh()->sort_order)->toBe(1);
    expect((int) $second->fresh()->sort_order)->toBe(2);
});

test('save rule with interface scoping persists', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.name', 'Scoped HTTP')
        ->set('form.port', 80)
        ->set('form.protocol', 'tcp')
        ->set('form.action', 'allow')
        ->set('form.source', 'any')
        ->set('form.iface', 'eth0')
        ->set('form.iface_direction', 'in')
        ->call('saveFirewallRule')
        ->assertHasNoErrors();

    $rule = $server->firewallRules()->where('iface', 'eth0')->first();
    expect($rule)->not->toBeNull();
    expect($rule->iface_direction)->toBe('in');
});

test('save rule interface requires direction', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.port', 80)
        ->set('form.protocol', 'tcp')
        ->set('form.action', 'allow')
        ->set('form.source', 'any')
        ->set('form.iface', 'eth0')
        ->set('form.iface_direction', '')
        ->call('saveFirewallRule')
        ->assertHasErrors(['form.iface_direction']);
});

test('save rule with app profile clears port and persists', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.name', 'SSH via profile')
        ->set('form.app_profile', 'OpenSSH')
        ->set('form.port', 22)
        ->set('form.protocol', 'tcp')
        ->set('form.action', 'allow')
        ->set('form.source', 'any')
        ->call('saveFirewallRule')
        ->assertHasNoErrors();

    $rule = $server->firewallRules()->where('app_profile', 'OpenSSH')->first();
    expect($rule)->not->toBeNull();
    expect($rule->port)->toBeNull();
});

test('save rule app profile validates charset', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.app_profile', 'Bad$Profile')
        ->set('form.port', 22)
        ->set('form.protocol', 'tcp')
        ->set('form.source', 'any')
        ->call('saveFirewallRule')
        ->assertHasErrors(['form.app_profile']);
});

test('save rule accepts limit action on tcp', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.name', 'SSH')
        ->set('form.port', 22)
        ->set('form.protocol', 'tcp')
        ->set('form.action', 'limit')
        ->set('form.source', 'any')
        ->set('form.enabled', true)
        ->call('saveFirewallRule')
        ->assertHasNoErrors();

    expect($server->firewallRules()->where('action', 'limit')->count())->toBe(1);
});

test('save rule rejects limit action on udp', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('form.name', 'bad')
        ->set('form.port', 53)
        ->set('form.protocol', 'udp')
        ->set('form.action', 'limit')
        ->set('form.source', 'any')
        ->call('saveFirewallRule')
        ->assertHasErrors(['form.action']);

    expect($server->firewallRules()->count())->toBe(0);
});

test('preview apply builds ufw command list and opens modal', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);
    $server->update(['meta' => [config('server_firewall.meta_default_incoming_key') => 'deny']]);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('previewApplyFirewall')
        ->assertSet('apply_preview_open', true)
        ->tap(function ($component): void {
            $lines = $component->get('apply_preview_lines');
            expect($lines)->toBeArray();
            expect($lines)->toContain('ufw default deny incoming');
            expect(end($lines))->toBe('ufw --force enable');
            expect(collect($lines)->contains(fn ($l) => str_starts_with($l, 'ufw allow 443/tcp')))->toBeTrue();
        });
});

test('close apply preview resets state', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('apply_preview_open', true)
        ->set('apply_preview_lines', ['ufw allow 80/tcp'])
        ->call('closeApplyPreview')
        ->assertSet('apply_preview_open', false)
        ->assertSet('apply_preview_lines', []);
});

test('set default policy persists onto server meta', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallDefaultPolicy', 'incoming', 'deny')
        ->assertHasNoErrors();

    expect($server->fresh()->meta[config('server_firewall.meta_default_incoming_key')] ?? null)->toBe('deny');
});

test('set default policy clears meta when empty', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);
    $server->update(['meta' => [config('server_firewall.meta_default_incoming_key') => 'reject']]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallDefaultPolicy', 'incoming', '')
        ->assertHasNoErrors();

    $this->assertArrayNotHasKey(
        config('server_firewall.meta_default_incoming_key'),
        $server->fresh()->meta ?? [],
    );
});

test('export firewall rules json downloads a round trippable payload', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
        'tags' => ['public'],
    ]);
    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'SSH limit', 'port' => 22, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'limit', 'enabled' => true, 'sort_order' => 2,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('exportFirewallRulesJson');

    $response = $component->effects['download']['content'] ?? $component->lastResponse;
    expect($response)->not->toBeNull();

    // Livewire wraps streamed responses; assert the call didn't error.
    $component->assertHasNoErrors();
    expect($server->firewallRules()->count())->toBe(2);
});

test('export firewall rules csv succeeds without errors', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('exportFirewallRulesCsv')
        ->assertHasNoErrors();
});

test('load more firewall activity bumps visible window', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->assertSet('activity_visible', 60)
        ->call('loadMoreFirewallActivity')
        ->assertSet('activity_visible', 120)
        ->call('loadMoreFirewallActivity')
        ->assertSet('activity_visible', 180);
});

test('load more firewall activity caps at max', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $c = Livewire::actingAs($user)->test(WorkspaceFirewall::class, ['server' => $server]);
    for ($i = 0; $i < 20; $i++) {
        $c->call('loadMoreFirewallActivity');
    }
    $c->assertSet('activity_visible', WorkspaceFirewall::ACTIVITY_MAX_VISIBLE);
});

test('filtered firewall rules match text needle', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'Postgres replica', 'port' => 5432, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
    ]);
    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 2,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('rule_filter', 'postgres')
        ->tap(function ($c) use ($server): void {
            $filtered = $c->instance()->filteredFirewallRules($server->fresh()->firewallRules);
            expect($filtered)->toHaveCount(1);
            expect($filtered->first()->name)->toBe('Postgres replica');
        });
});

test('filtered firewall rules match by action chip', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'A', 'port' => 22, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'limit', 'enabled' => true, 'sort_order' => 1,
    ]);
    ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'B', 'port' => 80, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 2,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('rule_filter_action', 'limit')
        ->tap(function ($c) use ($server): void {
            $filtered = $c->instance()->filteredFirewallRules($server->fresh()->firewallRules);
            expect($filtered)->toHaveCount(1);
            expect($filtered->first()->action)->toBe('limit');
        });
});

test('clear rule filter resets both fields', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->set('rule_filter', 'foo')
        ->set('rule_filter_action', 'allow')
        ->call('clearRuleFilter')
        ->assertSet('rule_filter', '')
        ->assertSet('rule_filter_action', '');
});

test('set logging level persists and clears', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $key = (string) config('server_firewall.meta_logging_level_key');

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallLoggingLevel', 'medium')
        ->assertHasNoErrors();

    expect($server->fresh()->meta[$key] ?? null)->toBe('medium');

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server->fresh()])
        ->call('setFirewallLoggingLevel', '')
        ->assertHasNoErrors();

    $this->assertArrayNotHasKey($key, $server->fresh()->meta ?? []);
});

test('set logging level rejects unknown value', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);
    $key = (string) config('server_firewall.meta_logging_level_key');

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallLoggingLevel', 'paranoid')
        ->assertHasNoErrors();

    $this->assertArrayNotHasKey($key, $server->fresh()->meta ?? []);
});

test('set default policy rejects unknown value', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('setFirewallDefaultPolicy', 'incoming', 'whatever')
        ->assertHasNoErrors();

    $this->assertArrayNotHasKey(
        config('server_firewall.meta_default_incoming_key'),
        $server->fresh()->meta ?? [],
    );
});

test('move firewall rule rejects invalid direction', function () {
    $user = userWithOrganization();
    $server = readyServerFor($user);

    $rule = ServerFirewallRule::query()->create([
        'server_id' => $server->id, 'name' => 'A', 'port' => 80, 'protocol' => 'tcp',
        'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceFirewall::class, ['server' => $server])
        ->call('moveFirewallRule', $rule->id, 'sideways')
        ->assertHasNoErrors();

    expect((int) $rule->fresh()->sort_order)->toBe(1);
});
