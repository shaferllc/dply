<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ResizeServerJob;
use App\Jobs\SyncServerProviderSpecsJob;
use App\Livewire\Concerns\InteractsWithUnsavedChangesBar;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesExtendedServerSettings;
use App\Livewire\Servers\Concerns\ManagesServerNoteComments;
use App\Livewire\Servers\Concerns\ManagesServerNoteExports;
use App\Livewire\Servers\Concerns\ManagesServerNotes;
use App\Livewire\Servers\Concerns\ManagesWorkspaceSettingsForm;
use App\Models\Server;
use App\Services\Servers\ServerCostCard;
use App\Services\Servers\ServerHealthProbe;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\ServerResizeOptions;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Settings card: panel head, the category strip, and the active section.
 *
 * Split out of {@see WorkspaceSettings} because that component's view is the
 * whole page. Switching category there re-rendered the entire workspace shell —
 * sidebar, breadcrumbs, banner, command palette and their queries — to change
 * one panel. Measured, the panel render was ~0.5s against a ~3.5s document, and
 * that weight is what made every earlier approach fail: an in-place wire:click
 * action wedged the component when a second click landed mid-flight, while
 * <a wire:navigate> links avoided the wedge but re-fetched the whole document
 * and re-served a page-sized skeleton on every switch.
 *
 * With the strip living here, a category click updates only this component. The
 * request is small, the shell never moves, and the skeleton lands in place.
 *
 * All the section state (forms, notes, cost, removal flow) moved with the views
 * it backs — wire:model paths resolve against whichever component renders them,
 * so the traits had to follow the markup.
 */
#[Lazy]
class SettingsCard extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use InteractsWithUnsavedChangesBar;
    use ManagesExtendedServerSettings;
    use ManagesServerNoteComments;
    use ManagesServerNoteExports;
    use ManagesServerNotes;
    use ManagesWorkspaceSettingsForm;

    /**
     * Active category, carried as ?tab=. The default is omitted so the canonical
     * URL stays the bare /servers/{server}/settings.
     */
    #[Url(as: 'tab', except: 'connection')]
    public string $section = 'connection';

    /** @var array<string, mixed>|null Most recent inline test-connection result. */
    public ?array $testConnectionResult = null;

    /** Resize modal state. */
    public bool $showResizeModal = false;

    public string $resizeTarget = '';

    /** Populated when the modal opens; null while it has never been opened. */
    public ?array $resizeCatalog = null;

    public ?string $resizeError = null;

    /** False for providers that reboot in place (Vultr) rather than stopping the box. */
    public bool $resizePowerCycles = true;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        // An unknown tab is a typo or a stale link, not a missing page.
        if (! array_key_exists($this->section, $this->settingsWorkspaceTabs())) {
            $this->section = 'connection';
        }

        $this->syncSettingsFormFromServer();
        $this->syncExtendedServerSettingsFromServer();
    }

    /**
     * Switch category in place. Cheap now that this component is just the card,
     * so there's no page-sized render for a second click to collide with.
     */
    public function setSection(string $section): void
    {
        $this->section = array_key_exists($section, $this->settingsWorkspaceTabs())
            ? $section
            : 'connection';
    }

    #[Computed]
    public function canEditServerSettings(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }

    /**
     * Post-resize verification: re-read size/specs from the cloud provider and
     * reconcile the stored copy, then re-probe the box.
     */
    public function syncProviderSpecs(): void
    {
        $this->authorize('update', $this->server);

        SyncServerProviderSpecsJob::dispatch($this->server);
        $this->toastSuccess(__('Verifying with the provider — stored size and specs will update shortly.'));
    }

    /** Whether this server's provider supports an in-app resize (DigitalOcean only). */
    #[Computed]
    public function canResizeServer(): bool
    {
        return app(ServerResizeOptions::class)->supports($this->server)
            && $this->canEditServerSettings();
    }

    /**
     * Load the legal size list and open the modal. The catalog is fetched here
     * rather than in render() so the provider API is only hit when the operator
     * actually asks — every Settings page view would otherwise pay for it.
     */
    public function openResizeModal(ServerResizeOptions $options): void
    {
        $this->authorize('update', $this->server);

        $this->resizeError = null;
        $this->resizeTarget = '';

        try {
            $this->resizeCatalog = $options->forServer($this->server);
            $this->resizePowerCycles = $options->requiresPowerCycle($this->server);
        } catch (\Throwable $e) {
            $this->resizeCatalog = null;
            $this->resizeError = $e->getMessage();
        }

        $this->showResizeModal = true;
    }

    /**
     * Queue the resize. Validation is re-run in the job against live droplet
     * facts — this check is only here to fail fast with a message.
     */
    public function resizeServer(ServerResizeOptions $options): void
    {
        $this->authorize('update', $this->server);

        try {
            $target = $options->resolveTarget($this->server, $this->resizeTarget);
        } catch (\Throwable $e) {
            $this->resizeError = $e->getMessage();

            return;
        }

        ResizeServerJob::dispatch($this->server, $target['slug'], $target['grows_disk'], auth()->id());

        $this->showResizeModal = false;
        $this->resizeTarget = '';
        $this->toastSuccess(__('Resizing to :size — the server will be powered off and back on. This takes a few minutes.', [
            'size' => $target['slug'],
        ]));
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

        $this->testConnectionResult = $probe->probe($this->server);

        $this->server->update([
            'last_health_check_at' => now(),
            'health_status' => $this->testConnectionResult['ok'] ? Server::HEALTH_REACHABLE : Server::HEALTH_UNREACHABLE,
        ]);
        $this->server->refresh();
    }

    /**
     * Lazy skeleton for the card, shaped for the requested section.
     *
     * #[Url] is applied from the attribute's own mount hook, which for a #[Lazy]
     * component runs on the hydrate request — so $section is still the class
     * default here. Read ?tab= directly, or a deep link paints Connection and
     * then jumps once the real render lands.
     */
    public function placeholder(): View
    {
        $tabs = $this->settingsWorkspaceTabs();
        $requested = request()->query('tab');

        // Passed as `skeletonSection`, deliberately NOT as `section`.
        //
        // SupportLazyLoading regenerates this view with the component's own
        // public properties, so anything handed over under a property's name is
        // overwritten by that property — a `section` of 'notes' still rendered
        // the Connection skeleton, with its half-width two-up fields, for every
        // section. Assigning $this->section instead is worse: mutating a public
        // property here desyncs the lazy payload and the card never hydrates at
        // all. A name that isn't a property sidesteps both.
        $skeletonSection = is_string($requested) && array_key_exists($requested, $tabs)
            ? $requested
            : 'connection';

        return view('livewire.servers.partials.settings-card-placeholder', [
            'tabs' => $tabs,
            'skeletonSection' => $skeletonSection,
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

        return view('livewire.servers.settings-card', [
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
