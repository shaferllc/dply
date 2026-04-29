<?php

namespace App\Livewire\Settings;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Hub extends Component
{
    /** @var 'profile'|'servers' */
    public string $section = 'profile';

    /** @var array<string, mixed> */
    public array $ui = [];

    /** @var array<string, mixed> */
    public array $organizationServerSite = [];

    /**
     * @var array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int}
     */
    public array $organizationInsights = [];

    /** @var array<string, mixed> */
    public array $teamServerSite = [];

    public ?string $selectedTeamId = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->ui = $user->mergedUiPreferences();

        $legacyTab = request()->query('tab');
        if (in_array($legacyTab, ['servers', 'servers-sites'], true)) {
            $this->redirect(route('settings.servers'), navigate: true);

            return;
        }

        $this->section = match (request()->route()?->getName()) {
            'settings.servers' => 'servers',
            default => 'profile',
        };

        $org = $user->currentOrganization();
        $this->hydrateServerSiteState($org);
    }

    public function updatedSelectedTeamId(mixed $value): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        $defaults = config('user_preferences.team_server_site_defaults', []);

        if (! $org || ! $value) {
            $this->teamServerSite = $defaults;
            $this->selectedTeamId = null;

            return;
        }

        $team = $org->teams()->whereKey($value)->first();
        if ($team instanceof Team) {
            $this->teamServerSite = $team->mergedTeamPreferences();
            $this->selectedTeamId = (string) $team->id;
        } else {
            $this->teamServerSite = $defaults;
            $this->selectedTeamId = null;
        }
    }

    protected function hydrateServerSiteState(?Organization $org): void
    {
        $teamDefaults = config('user_preferences.team_server_site_defaults', []);
        $orgDefaults = config('user_preferences.organization_server_site_defaults', []);

        if (! $org instanceof Organization) {
            $this->organizationServerSite = $orgDefaults;
            $this->organizationInsights = $this->defaultInsightsState();
            $this->teamServerSite = $teamDefaults;
            $this->selectedTeamId = null;

            return;
        }

        $this->organizationServerSite = $org->mergedServerSitePreferences();
        $this->organizationInsights = $this->insightsStateFromOrg($org);

        $teams = $org->teams()->orderBy('name')->get();
        if ($teams->isEmpty()) {
            $this->teamServerSite = $teamDefaults;
            $this->selectedTeamId = null;

            return;
        }

        $firstId = $teams->first()->id;
        $this->selectedTeamId = $teams->contains('id', $this->selectedTeamId)
            ? $this->selectedTeamId
            : (string) $firstId;

        $team = $teams->firstWhere('id', $this->selectedTeamId);
        $this->teamServerSite = $team instanceof Team
            ? $team->mergedTeamPreferences()
            : $teamDefaults;
    }

    /**
     * @return array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int}
     */
    protected function defaultInsightsState(): array
    {
        $d = config('insights.organization_defaults', []);
        $freq = ($d['digest_frequency'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';

        return [
            'digest_non_critical' => (bool) ($d['digest_non_critical'] ?? false),
            'digest_frequency' => $freq,
            'quiet_hours_enabled' => (bool) ($d['quiet_hours_enabled'] ?? false),
            'quiet_hours_start' => (int) ($d['quiet_hours_start'] ?? 22),
            'quiet_hours_end' => (int) ($d['quiet_hours_end'] ?? 7),
        ];
    }

    /**
     * @return array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int}
     */
    protected function insightsStateFromOrg(Organization $org): array
    {
        $m = $org->mergedInsightsPreferences();
        $freq = ($m['digest_frequency'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';

        return [
            'digest_non_critical' => (bool) ($m['digest_non_critical'] ?? false),
            'digest_frequency' => $freq,
            'quiet_hours_enabled' => (bool) ($m['quiet_hours_enabled'] ?? false),
            'quiet_hours_start' => (int) ($m['quiet_hours_start'] ?? 22),
            'quiet_hours_end' => (int) ($m['quiet_hours_end'] ?? 7),
        ];
    }

    public function saveProfile(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $this->validate([
            'ui.newsletter' => ['boolean'],
            'ui.keyboard_shortcuts' => ['boolean'],
            'ui.redirect_home_to_app' => ['boolean'],
            'ui.subscription_invoice_emails' => ['boolean'],
            'ui.theme' => [Rule::in(config('user_preferences.theme_options', []))],
            'ui.navigation_layout' => [Rule::in(config('user_preferences.navigation_layout_options', []))],
            'ui.notification_position' => [Rule::in(array_keys(config('user_preferences.notification_positions', [])))],
        ]);

        $keys = array_keys(config('user_preferences.defaults', []));
        $filtered = array_intersect_key($this->ui, array_flip($keys));

        $user->update([
            'ui_preferences' => array_merge($user->ui_preferences ?? [], $filtered),
        ]);

        $this->ui = $user->fresh()->mergedUiPreferences();

        session()->flash('success', __('Profile settings saved.'));
    }

    public function saveOrganizationServersSites(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        if (! $org instanceof Organization) {
            session()->flash('error', __('Select or create an organization to save these defaults.'));

            return;
        }

        $this->authorize('update', $org);

        $this->validate([
            'organizationServerSite.email_server_passwords' => ['boolean'],
            'organizationServerSite.set_timezone_on_new_servers' => ['boolean'],
        ]);

        $keys = array_keys(config('user_preferences.organization_server_site_defaults', []));
        $filtered = array_intersect_key($this->organizationServerSite, array_flip($keys));

        $org->update([
            'server_site_preferences' => array_merge($org->server_site_preferences ?? [], $filtered),
        ]);

        $this->organizationServerSite = $org->fresh()->mergedServerSitePreferences();

        session()->flash('success', __('Organization settings saved.'));
    }

    public function saveOrganizationInsights(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        if (! $org instanceof Organization) {
            session()->flash('error', __('Select or create an organization to save Insights preferences.'));

            return;
        }

        $this->authorize('update', $org);

        $this->validate([
            'organizationInsights.digest_non_critical' => ['boolean'],
            'organizationInsights.digest_frequency' => ['required', 'string', Rule::in(['daily', 'weekly'])],
            'organizationInsights.quiet_hours_enabled' => ['boolean'],
            'organizationInsights.quiet_hours_start' => ['required', 'integer', 'min:0', 'max:23'],
            'organizationInsights.quiet_hours_end' => ['required', 'integer', 'min:0', 'max:23'],
        ]);

        $stored = $org->insights_preferences ?? [];
        if (! is_array($stored)) {
            $stored = [];
        }

        $stored['digest_non_critical'] = (bool) ($this->organizationInsights['digest_non_critical'] ?? false);
        $stored['digest_frequency'] = ($this->organizationInsights['digest_frequency'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';
        $stored['quiet_hours_enabled'] = (bool) ($this->organizationInsights['quiet_hours_enabled'] ?? false);
        $stored['quiet_hours_start'] = (int) ($this->organizationInsights['quiet_hours_start'] ?? 22);
        $stored['quiet_hours_end'] = (int) ($this->organizationInsights['quiet_hours_end'] ?? 7);

        $org->update(['insights_preferences' => $stored]);

        $this->organizationInsights = $this->insightsStateFromOrg($org->fresh());

        session()->flash('success', __('Insights preferences saved.'));
    }

    public function saveTeamServersSites(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        if (! $org instanceof Organization) {
            session()->flash('error', __('Select or create an organization first.'));

            return;
        }

        $team = $this->selectedTeamId
            ? $org->teams()->whereKey($this->selectedTeamId)->first()
            : null;

        if (! $team instanceof Team) {
            session()->flash('error', __('Select a team to save team defaults.'));

            return;
        }

        if (! $team->userCanManageSshKeys($user)) {
            abort(403);
        }

        $this->validate([
            'teamServerSite.show_server_updates_in_list' => ['boolean'],
            'teamServerSite.isolate_new_sites' => ['boolean'],
            'teamServerSite.default_server_sort' => [Rule::in(array_keys(config('user_preferences.server_sort_options', [])))],
            'teamServerSite.default_site_sort' => [Rule::in(array_keys(config('user_preferences.site_sort_options', [])))],
        ]);

        $keys = array_keys(config('user_preferences.team_server_site_defaults', []));
        $filtered = array_intersect_key($this->teamServerSite, array_flip($keys));

        $team->update([
            'preferences' => array_merge($team->preferences ?? [], $filtered),
        ]);

        $this->teamServerSite = $team->fresh()->mergedTeamPreferences();

        session()->flash('success', __('Team settings saved.'));
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        $teams = $org?->teams()->orderBy('name')->get() ?? collect();

        $selectedTeam = $this->selectedTeamId
            ? $teams->firstWhere('id', $this->selectedTeamId)
            : null;

        $canEditTeamPrefs = $selectedTeam instanceof Team
            && $selectedTeam->userCanManageSshKeys($user);

        return view('livewire.settings.hub', [
            'currentOrg' => $org,
            'teams' => $teams,
            'selectedTeam' => $selectedTeam,
            'canEditOrgPrefs' => $org?->hasAdminAccess($user) ?? false,
            'canEditTeamPrefs' => $canEditTeamPrefs,
            'userTimezoneLabel' => $user->timezone ?? 'UTC',
        ]);
    }
}
