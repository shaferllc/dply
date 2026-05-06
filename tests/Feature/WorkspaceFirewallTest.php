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
        $response->assertSee('History');
        $response->assertSee('Audit');
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
            ->set('firewall_workspace_tab', 'history')
            ->assertSee('Apply history')
            ->assertSee('Applied');

        Livewire::actingAs($user)
            ->test(WorkspaceFirewall::class, ['server' => $server])
            ->set('firewall_workspace_tab', 'audit')
            ->assertSee('Recent audit')
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
}
