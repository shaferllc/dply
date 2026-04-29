<?php

namespace App\Livewire\Servers;

use App\Jobs\CheckServerHealthJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesExtendedServerSettings;
use App\Livewire\Servers\Concerns\ManagesWorkspaceSettingsForm;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSettings extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesExtendedServerSettings;
    use ManagesWorkspaceSettingsForm;

    /** @var string Settings sub-page slug (see config server_settings.workspace_tabs). */
    public string $section = 'connection';

    public function mount(Server $server, ?string $section = null): void
    {
        if ($section === null) {
            $this->redirect(route('servers.settings', ['server' => $server, 'section' => 'connection']), navigate: true);

            return;
        }

        $allowed = array_keys(config('server_settings.workspace_tabs', []));
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->bootWorkspace($server);
        $this->server->refresh();
        $this->syncSettingsFormFromServer();
        $this->syncExtendedServerSettingsFromServer();
    }

    #[Computed]
    public function canEditServerSettings(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }

    public function checkHealth(): void
    {
        $this->authorize('view', $this->server);
        if ($this->server->status === Server::STATUS_READY && ! empty($this->server->ip_address)) {
            CheckServerHealthJob::dispatch($this->server);
        }
        $this->toastSuccess(__('Health check has been queued. Status will update shortly.'));
    }

    public function render(): View
    {
        $this->server->refresh();
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

        return view('livewire.servers.workspace-settings', [
            'section' => $this->section,
            'workspaces' => $this->workspacesForCurrentServerOrg(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
