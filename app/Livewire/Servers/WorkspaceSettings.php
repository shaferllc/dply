<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesExtendedServerSettings;
use App\Livewire\Servers\Concerns\ManagesServerNotes;
use App\Livewire\Servers\Concerns\ManagesWorkspaceSettingsForm;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Services\Servers\ServerCostCard;
use App\Services\Servers\ServerHealthProbe;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSettings extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesExtendedServerSettings;
    use ManagesServerNotes;
    use ManagesWorkspaceSettingsForm;
    use RendersWorkspacePlaceholder;

    /** @var string Settings sub-page slug (see config server_settings.workspace_tabs). */
    public string $section = 'connection';

    /** @var array<string, mixed>|null Most recent inline test-connection result. Null until the user clicks Test connection. */
    public ?array $testConnectionResult = null;

    public function mount(Server $server, ?string $section = null): void
    {
        if ($section === null) {
            $this->redirect(route('servers.settings', ['server' => $server, 'section' => 'connection']), navigate: true);

            return;
        }

        if ($section === 'inventory' && Feature::active('workspace.patch_advisor')) {
            $this->redirect(route('servers.patches', $server), navigate: true);

            return;
        }

        // The per-server outbound webhook moved to the Notifications page so all
        // of a server's event delivery lives in one place. Redirect the old
        // Settings → Webhook URL (and any bookmarks) to its new home.
        if ($section === 'webhook') {
            $this->redirect(route('servers.notifications', ['server' => $server, 'tab' => 'webhooks']), navigate: true);

            return;
        }

        $allowed = array_keys($this->settingsWorkspaceTabs());
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->bootWorkspace($server);
        $this->syncSettingsFormFromServer();
        $this->syncExtendedServerSettingsFromServer();
    }

    #[Computed]
    public function canEditServerSettings(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }

    public function checkHealth(ServerHealthProbe $probe): void
    {
        $this->authorize('view', $this->server);

        set_time_limit(45);

        if ($this->server->status !== Server::STATUS_READY || empty($this->server->ip_address)) {
            $this->testConnectionResult = [
                'ok' => false,
                'method' => null,
                'latency_ms' => null,
                'host' => $this->server->ip_address ?: null,
                'port' => (int) ($this->server->ssh_port ?: 22),
                'http_status' => null,
                'http_url' => null,
                'error' => __('Server is not ready or has no IP address.'),
                'tested_at' => now()->toIso8601String(),
            ];

            return;
        }

        $result = $probe->probe($this->server);
        $this->testConnectionResult = $result;

        $this->server->update([
            'last_health_check_at' => now(),
            'health_status' => $result['ok'] ? Server::HEALTH_REACHABLE : Server::HEALTH_UNREACHABLE,
        ]);
        $this->server->refresh();
    }

    /**
     * Override the trait placeholder so the Settings sub-tab strip stays
     * visible (with the destination section highlighted) while the body
     * lazy-loads — only the content area below the sub-tabs skeletons.
     */
    public function placeholder(): View
    {
        return view('livewire.servers.partials.workspace-subtab-placeholder', [
            'server' => $this->server,
            'active' => 'settings',
            'title' => __('Settings'),
            'tabs' => $this->settingsWorkspaceTabs(),
            'section' => $this->section,
            'routeName' => 'servers.settings',
            'idPrefix' => 'settings-tab-',
            'ariaLabel' => __('Settings categories'),
        ]);
    }

    public function render(): View
    {
        $this->server->load([
            'sites.domains',
            'serverDatabases',
            'cronJobs',
            'supervisorPrograms',
            'firewallRules',
            'authorizedKeys',
            'recipes',
            'providerCredential',
        ]);

        $costReport = null;
        if ($this->section === 'governance'
            && Feature::active('workspace.server_cost')
            && $this->server->isVmHost()
            && ! $this->server->isManagedProductHost()) {
            $costReport = app(ServerCostCard::class)->forServer($this->server);
        }

        return view('livewire.servers.workspace-settings', [
            'section' => $this->section,
            'settingsTabs' => $this->settingsWorkspaceTabs(),
            'workspaces' => $this->workspacesForCurrentServerOrg(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'costReport' => $costReport,
        ]);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    protected function settingsWorkspaceTabs(): array
    {
        $tabs = config('server_settings.workspace_tabs', []);

        if (Feature::active('workspace.patch_advisor')) {
            unset($tabs['inventory']);
        }

        return $tabs;
    }
}
