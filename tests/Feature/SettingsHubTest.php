<?php

namespace Tests\Feature;

use App\Livewire\Settings\Hub;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_settings_hub(): void
    {
        $this->get(route('settings.index'))->assertRedirect();
    }

    public function test_authenticated_user_can_save_profile_preferences(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('ui.newsletter', false)
            ->set('ui.theme', 'dark')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->mergedUiPreferences()['newsletter']);
        $this->assertSame('dark', $user->mergedUiPreferences()['theme']);
    }

    public function test_org_admin_can_save_organization_server_site_preferences(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('organizationServerSite.email_server_passwords', false)
            ->set('organizationServerSite.set_timezone_on_new_servers', true)
            ->call('saveOrganizationServersSites')
            ->assertHasNoErrors();

        $org->refresh();
        $this->assertFalse($org->mergedServerSitePreferences()['email_server_passwords']);
        $this->assertTrue($org->mergedServerSitePreferences()['set_timezone_on_new_servers']);
    }

    public function test_non_admin_cannot_save_organization_server_site_preferences(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('organizationServerSite.email_server_passwords', false)
            ->call('saveOrganizationServersSites')
            ->assertForbidden();
    }

    public function test_org_admin_can_save_organization_firewall_settings(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('organizationFirewall.require_second_approval', true)
            ->set('organizationFirewall.notify_drift_webhook', true)
            ->set('organizationFirewall.synthetic_probe_url', 'https://example.com/health')
            ->call('saveOrganizationFirewall')
            ->assertHasNoErrors();

        $org->refresh();
        $fw = $org->mergedFirewallSettings();
        $this->assertTrue($fw['require_second_approval']);
        $this->assertTrue($fw['notify_drift_webhook']);
        $this->assertSame('https://example.com/health', $fw['synthetic_probe_url']);
    }

    public function test_non_admin_cannot_save_organization_firewall_settings(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('organizationFirewall.require_second_approval', true)
            ->call('saveOrganizationFirewall')
            ->assertForbidden();
    }

    public function test_org_admin_can_save_organization_insights_preferences(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('organizationInsights.digest_non_critical', true)
            ->set('organizationInsights.digest_frequency', 'weekly')
            ->set('organizationInsights.quiet_hours_enabled', true)
            ->set('organizationInsights.quiet_hours_start', 23)
            ->set('organizationInsights.quiet_hours_end', 6)
            ->call('saveOrganizationInsights')
            ->assertHasNoErrors();

        $org->refresh();
        $prefs = $org->mergedInsightsPreferences();
        $this->assertTrue($prefs['digest_non_critical']);
        $this->assertSame('weekly', $prefs['digest_frequency']);
        $this->assertTrue($prefs['quiet_hours_enabled']);
        $this->assertSame(23, $prefs['quiet_hours_start']);
        $this->assertSame(6, $prefs['quiet_hours_end']);
    }

    public function test_team_admin_can_save_team_preferences_without_org_admin_role(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $team->users()->attach($user->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('selectedTeamId', $team->id)
            ->set('teamServerSite.default_server_sort', 'name')
            ->set('teamServerSite.show_server_updates_in_list', true)
            ->call('saveTeamServersSites')
            ->assertHasNoErrors();

        $team->refresh();
        $this->assertSame('name', $team->mergedTeamPreferences()['default_server_sort']);
        $this->assertTrue($team->mergedTeamPreferences()['show_server_updates_in_list']);
    }

    public function test_team_member_cannot_save_team_preferences(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $team->users()->attach($user->id, ['role' => 'member']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(Hub::class)
            ->set('activeTab', 'servers')
            ->set('selectedTeamId', $team->id)
            ->set('teamServerSite.default_server_sort', 'name')
            ->call('saveTeamServersSites')
            ->assertForbidden();
    }
}
