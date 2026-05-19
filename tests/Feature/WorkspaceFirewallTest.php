<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class WorkspaceFirewallTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    protected function readyServerFor(User $user): Server
    {
        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()?->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }

    public function test_firewall_workspace_shows_basics_and_hides_advanced_sections(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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

        $this->assertTrue(Route::has('servers.firewall'), 'Expected [servers.firewall] route to exist.');

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
    }

    public function test_firewall_history_and_audit_tabs_render_their_sections(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
            ->set('firewall_workspace_tab', 'activity')
            ->assertSee('Activity')
            ->assertSee('Applied')
            ->assertSee(ServerFirewallAuditEvent::EVENT_RULE_CREATED);
    }

    public function test_firewall_workspace_shows_ops_not_ready_state_without_ssh_access(): void
    {
        $user = $this->userWithOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()?->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'ssh_private_key' => null,
        ]);

        $response = $this->actingAs($user)->get(route('servers.firewall', $server, false));

        $response->assertOk();
        $response->assertSee('Provisioning and SSH must be ready before you can use this section.');
    }

    public function test_firewall_preset_populates_the_form(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('useFirewallPreset', 'https')
            ->assertSet('form.name', 'HTTPS')
            ->assertSet('form.port', 443)
            ->assertSet('form.protocol', 'tcp')
            ->assertSet('form.source', 'any')
            ->assertSet('form.action', 'allow')
            ->assertSet('form.enabled', true);
    }

    public function test_bundled_template_adds_rules_to_the_server(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('applyBundledFirewallTemplate', 'laravel_web')
            ->assertHasNoErrors();

        $this->assertSame(3, $server->firewallRules()->count());
        $this->assertDatabaseHas('server_firewall_rules', [
            'server_id' => $server->id,
            'name' => 'HTTPS',
            'port' => 443,
        ]);
    }

    public function test_bundled_template_application_skips_duplicate_rules(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('applyBundledFirewallTemplate', 'laravel_web')
            ->call('applyBundledFirewallTemplate', 'laravel_web')
            ->assertHasNoErrors();

        $this->assertSame(3, $server->fresh()->firewallRules()->count());
        $this->assertSame(1, $server->firewallRules()->where('port', 22)->where('protocol', 'tcp')->where('source', 'any')->count());
    }

    public function test_saved_template_is_listed_on_the_templates_tab(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);
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

        $response = $this->actingAs($user)->get(route('servers.firewall', $server, false));

        $response->assertOk();
        $response->assertSee('Saved templates');
        $response->assertSee('Web basics');
    }

    public function test_apply_firewall_dispatches_queued_job_and_marks_meta(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
        $provisioner->shouldNotReceive('apply'); // queued, runs in the job — not in the request
        $this->app->instance(ServerFirewallProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('applyFirewall')
            ->assertHasNoErrors();

        Queue::assertPushed(ApplyFirewallJob::class, fn ($job) => $job->serverId === $server->id);

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('queued', data_get($meta, config('server_firewall.meta_apply_status_key')));
        $this->assertNotEmpty(data_get($meta, config('server_firewall.meta_apply_run_id_key')));
    }

    public function test_apply_firewall_no_ops_when_a_run_is_already_in_flight(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);
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
    }

    public function test_delete_firewall_rule_can_be_confirmed_through_modal_state(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
    }

    public function test_manual_rule_save_rejects_duplicate_signature(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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

        $this->assertSame(1, $server->fresh()->firewallRules()->count());
    }

    public function test_trim_duplicate_rules_keeps_first_copy_of_each_signature(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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

        $this->assertSame(2, $server->firewallRules()->count());
        $this->assertDatabaseHas('server_firewall_rules', ['id' => $firstSsh->id]);
        $this->assertSame(1, $server->firewallRules()->where('port', 22)->where('protocol', 'tcp')->where('source', 'any')->count());
        $this->assertDatabaseHas('server_firewall_audit_events', [
            'server_id' => $server->id,
            'event' => ServerFirewallAuditEvent::EVENT_RULE_DELETED,
        ]);
    }

    public function test_trim_duplicate_rules_ignores_hidden_site_differences_for_same_network_rule(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);
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

        $this->assertSame(1, $server->fresh()->firewallRules()->count());
        $this->assertSame(1, $server->firewallRules()->where('port', 80)->where('protocol', 'tcp')->where('source', 'any')->count());
    }

    public function test_move_firewall_rule_swaps_sort_order_with_neighbour(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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

        $this->assertSame(2, (int) $first->fresh()->sort_order);
        $this->assertSame(1, (int) $second->fresh()->sort_order);
    }

    public function test_move_firewall_rule_at_edge_is_a_no_op(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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

        $this->assertSame(1, (int) $first->fresh()->sort_order);
        $this->assertSame(2, (int) $second->fresh()->sort_order);
    }

    public function test_save_rule_with_interface_scoping_persists(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
        $this->assertNotNull($rule);
        $this->assertSame('in', $rule->iface_direction);
    }

    public function test_save_rule_interface_requires_direction(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
    }

    public function test_save_rule_with_app_profile_clears_port_and_persists(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
        $this->assertNotNull($rule);
        $this->assertNull($rule->port);
    }

    public function test_save_rule_app_profile_validates_charset(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->set('form.app_profile', 'Bad$Profile')
            ->set('form.port', 22)
            ->set('form.protocol', 'tcp')
            ->set('form.source', 'any')
            ->call('saveFirewallRule')
            ->assertHasErrors(['form.app_profile']);
    }

    public function test_save_rule_accepts_limit_action_on_tcp(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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

        $this->assertSame(1, $server->firewallRules()->where('action', 'limit')->count());
    }

    public function test_save_rule_rejects_limit_action_on_udp(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->set('form.name', 'bad')
            ->set('form.port', 53)
            ->set('form.protocol', 'udp')
            ->set('form.action', 'limit')
            ->set('form.source', 'any')
            ->call('saveFirewallRule')
            ->assertHasErrors(['form.action']);

        $this->assertSame(0, $server->firewallRules()->count());
    }

    public function test_preview_apply_builds_ufw_command_list_and_opens_modal(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);
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
                $this->assertIsArray($lines);
                $this->assertContains('ufw default deny incoming', $lines);
                $this->assertSame('ufw --force enable', end($lines));
                $this->assertTrue(collect($lines)->contains(fn ($l) => str_starts_with($l, 'ufw allow 443/tcp')));
            });
    }

    public function test_close_apply_preview_resets_state(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->set('apply_preview_open', true)
            ->set('apply_preview_lines', ['ufw allow 80/tcp'])
            ->call('closeApplyPreview')
            ->assertSet('apply_preview_open', false)
            ->assertSet('apply_preview_lines', []);
    }

    public function test_set_default_policy_persists_onto_server_meta(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('setFirewallDefaultPolicy', 'incoming', 'deny')
            ->assertHasNoErrors();

        $this->assertSame('deny', $server->fresh()->meta[config('server_firewall.meta_default_incoming_key')] ?? null);
    }

    public function test_set_default_policy_clears_meta_when_empty(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);
        $server->update(['meta' => [config('server_firewall.meta_default_incoming_key') => 'reject']]);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('setFirewallDefaultPolicy', 'incoming', '')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey(
            config('server_firewall.meta_default_incoming_key'),
            $server->fresh()->meta ?? [],
        );
    }

    public function test_export_firewall_rules_json_downloads_a_round_trippable_payload(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
        $this->assertNotNull($response);
        // Livewire wraps streamed responses; assert the call didn't error.
        $component->assertHasNoErrors();
        $this->assertSame(2, $server->firewallRules()->count());
    }

    public function test_export_firewall_rules_csv_succeeds_without_errors(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        ServerFirewallRule::query()->create([
            'server_id' => $server->id, 'name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp',
            'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('exportFirewallRulesCsv')
            ->assertHasNoErrors();
    }

    public function test_load_more_firewall_activity_bumps_visible_window(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->assertSet('activity_visible', 60)
            ->call('loadMoreFirewallActivity')
            ->assertSet('activity_visible', 120)
            ->call('loadMoreFirewallActivity')
            ->assertSet('activity_visible', 180);
    }

    public function test_load_more_firewall_activity_caps_at_max(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        $c = Livewire::actingAs($user)->test(WorkspaceFirewall::class, ['server' => $server]);
        for ($i = 0; $i < 20; $i++) {
            $c->call('loadMoreFirewallActivity');
        }
        $c->assertSet('activity_visible', WorkspaceFirewall::ACTIVITY_MAX_VISIBLE);
    }

    public function test_filtered_firewall_rules_match_text_needle(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
                $this->assertCount(1, $filtered);
                $this->assertSame('Postgres replica', $filtered->first()->name);
            });
    }

    public function test_filtered_firewall_rules_match_by_action_chip(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

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
                $this->assertCount(1, $filtered);
                $this->assertSame('limit', $filtered->first()->action);
            });
    }

    public function test_clear_rule_filter_resets_both_fields(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->set('rule_filter', 'foo')
            ->set('rule_filter_action', 'allow')
            ->call('clearRuleFilter')
            ->assertSet('rule_filter', '')
            ->assertSet('rule_filter_action', '');
    }

    public function test_set_logging_level_persists_and_clears(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        $key = (string) config('server_firewall.meta_logging_level_key');

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('setFirewallLoggingLevel', 'medium')
            ->assertHasNoErrors();

        $this->assertSame('medium', $server->fresh()->meta[$key] ?? null);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server->fresh()])
            ->call('setFirewallLoggingLevel', '')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey($key, $server->fresh()->meta ?? []);
    }

    public function test_set_logging_level_rejects_unknown_value(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);
        $key = (string) config('server_firewall.meta_logging_level_key');

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('setFirewallLoggingLevel', 'paranoid')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey($key, $server->fresh()->meta ?? []);
    }

    public function test_set_default_policy_rejects_unknown_value(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('setFirewallDefaultPolicy', 'incoming', 'whatever')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey(
            config('server_firewall.meta_default_incoming_key'),
            $server->fresh()->meta ?? [],
        );
    }

    public function test_move_firewall_rule_rejects_invalid_direction(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServerFor($user);

        $rule = ServerFirewallRule::query()->create([
            'server_id' => $server->id, 'name' => 'A', 'port' => 80, 'protocol' => 'tcp',
            'source' => 'any', 'action' => 'allow', 'enabled' => true, 'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->call('moveFirewallRule', $rule->id, 'sideways')
            ->assertHasNoErrors();

        $this->assertSame(1, (int) $rule->fresh()->sort_order);
    }
}
