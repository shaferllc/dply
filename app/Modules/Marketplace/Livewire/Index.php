<?php

namespace App\Modules\Marketplace\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Modules\Marketplace\Models\MarketplaceItem;
use App\Models\Script;
use App\Models\Server;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Marketplace\Scripts\CloneScriptPreset;
use App\Modules\Marketplace\Services\MarketplaceImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use RequiresFeature;
    use WithPagination;

    protected string $requiredFeature = 'surface.marketplace';

    use DispatchesToastNotifications;

    #[Url(history: true)]
    public string $category = 'all';

    #[Url(history: true)]
    public string $search = '';

    public ?string $deployModalItemId = null;

    public ?string $serverRecipeModalItemId = null;

    public ?string $runbookModalItemId = null;

    public ?string $deployServerId = null;

    public ?string $runbookWorkspaceId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(string $value): void
    {
        $this->resetPage();

        if ($value !== 'all' && ! array_key_exists($value, MarketplaceItem::categories())) {
            $this->category = 'all';
        }
    }

    public function resetFilters(): void
    {
        $this->resetPage();
        $this->category = 'all';
        $this->search = '';
    }

    public function openDeployImport(string $itemId): void
    {
        $item = MarketplaceItem::query()->active()->findOrFail($itemId);
        if ($item->recipe_type !== MarketplaceItem::RECIPE_DEPLOY_COMMAND) {
            return;
        }

        $servers = $this->serversForCurrentOrg();
        if ($servers->isEmpty()) {
            session()->flash('error', __('Add a server to this organization before importing a deploy recipe.'));

            return;
        }

        $this->deployModalItemId = $itemId;
        $this->deployServerId = $servers->first()->id;
    }

    public function openServerRecipeImport(string $itemId): void
    {
        $item = MarketplaceItem::query()->active()->findOrFail($itemId);
        if ($item->recipe_type !== MarketplaceItem::RECIPE_SERVER_RECIPE) {
            return;
        }

        $servers = $this->serversForCurrentOrg();
        if ($servers->isEmpty()) {
            session()->flash('error', __('Add a server to this organization before importing a saved command.'));

            return;
        }

        $this->serverRecipeModalItemId = $itemId;
        $this->deployServerId = $servers->first()->id;
    }

    public function openRunbookImport(string $itemId): void
    {
        $item = MarketplaceItem::query()->active()->findOrFail($itemId);
        if ($item->recipe_type !== MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK) {
            return;
        }

        $workspaces = $this->workspacesForCurrentOrg();
        if ($workspaces->isEmpty()) {
            session()->flash('error', __('Create a project in this organization before importing a runbook.'));

            return;
        }

        $this->runbookModalItemId = $itemId;
        $this->runbookWorkspaceId = $workspaces->first()->id;
    }

    public function closeServerImportModal(): void
    {
        $this->deployModalItemId = null;
        $this->serverRecipeModalItemId = null;
        $this->runbookModalItemId = null;
        $this->deployServerId = null;
        $this->runbookWorkspaceId = null;
    }

    public function confirmDeployImport(MarketplaceImportService $importService): void
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $this->deployModalItemId || ! $this->deployServerId) {
            return;
        }

        $item = MarketplaceItem::query()->active()->findOrFail($this->deployModalItemId);

        $org = $user->currentOrganization();
        if (! $org) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }
        $orgId = $org->id;

        $server = Server::query()
            ->where('organization_id', $orgId)
            ->whereKey($this->deployServerId)
            ->firstOrFail();

        $this->authorize('update', $server);

        $importService->importDeployCommand($user, $item, $server);

        audit_log($org, $user, 'marketplace.deploy_command_imported', $item, null, [
            'item_id' => (string) $item->id,
            'item_name' => $item->name,
            'server_id' => (string) $server->id,
            'server_name' => $server->name,
        ]);

        $this->closeServerImportModal();
        $this->toastSuccess(__('Deploy command imported to :server.', ['server' => $server->name]));
    }

    public function confirmServerRecipeImport(MarketplaceImportService $importService): void
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $this->serverRecipeModalItemId || ! $this->deployServerId) {
            return;
        }

        $item = MarketplaceItem::query()->active()->findOrFail($this->serverRecipeModalItemId);

        $org = $user->currentOrganization();
        if (! $org) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }
        $orgId = $org->id;

        $server = Server::query()
            ->where('organization_id', $orgId)
            ->whereKey($this->deployServerId)
            ->firstOrFail();

        $this->authorize('update', $server);

        $importService->importServerRecipe($user, $item, $server);

        audit_log($org, $user, 'marketplace.server_recipe_imported', $item, null, [
            'item_id' => (string) $item->id,
            'item_name' => $item->name,
            'server_id' => (string) $server->id,
            'server_name' => $server->name,
        ]);

        $this->closeServerImportModal();
        $this->toastSuccess(__('Saved command imported to :server.', ['server' => $server->name]));
    }

    public function confirmRunbookImport(MarketplaceImportService $importService): void
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $this->runbookModalItemId || ! $this->runbookWorkspaceId) {
            return;
        }

        $item = MarketplaceItem::query()->active()->findOrFail($this->runbookModalItemId);

        $org = $user->currentOrganization();
        if (! $org) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }
        $orgId = $org->id;

        $workspace = Workspace::query()
            ->where('organization_id', $orgId)
            ->whereKey($this->runbookWorkspaceId)
            ->firstOrFail();

        $this->authorize('update', $workspace);

        $runbook = $importService->importWorkspaceRunbook($user, $item, $workspace);

        audit_log($org, $user, 'marketplace.workspace_runbook_imported', $item, null, [
            'item_id' => (string) $item->id,
            'item_name' => $item->name,
            'workspace_id' => (string) $workspace->id,
            'workspace_name' => $workspace->name,
            'runbook_id' => (string) $runbook->id,
        ]);

        $this->closeServerImportModal();
        $this->toastSuccess(__('Runbook imported to :project.', ['project' => $workspace->name]));
    }

    public function importWebserverTemplate(string $itemId, MarketplaceImportService $importService): void
    {
        /** @var User $user */
        $user = Auth::user();
        $item = MarketplaceItem::query()->active()->findOrFail($itemId);

        try {
            $importService->importWebserverTemplate($user, $item);
        } catch (AuthorizationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $org = $user->currentOrganization();
        if ($org) {
            audit_log($org, $user, 'marketplace.webserver_template_imported', $item, null, [
                'item_id' => (string) $item->id,
                'item_name' => $item->name,
            ]);
        }

        session()->flash('success', __('Webserver template saved to your organization.'));
        if ($org) {
            $this->redirect(route('organizations.webserver-templates', $org), navigate: true);
        }
    }

    /**
     * Copy a preset script into the current organization and open it for edits.
     */
    public function cloneScriptPreset(string $itemId, CloneScriptPreset $cloner): mixed
    {
        $this->authorize('create', Script::class);

        $item = MarketplaceItem::query()->active()->findOrFail($itemId);
        if ($item->recipe_type !== MarketplaceItem::RECIPE_SCRIPT) {
            return null;
        }

        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        if (! $org) {
            session()->flash('error', __('Select an organization first.'));

            return null;
        }

        $script = $cloner->clone((string) ($item->payload['preset_key'] ?? ''), $org, $user);
        if ($script === null) {
            session()->flash('error', __('This marketplace script is not available.'));

            return null;
        }

        $this->toastSuccess(__('Script added to your organization. You can edit it below.'));

        return $this->redirect(route('scripts.edit', $script), navigate: true);
    }

    /**
     * @return Collection<int, Server>
     */
    protected function serversForCurrentOrg(): Collection
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        if (! $org) {
            return collect();
        }

        return Server::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Workspace>
     */
    protected function workspacesForCurrentOrg(): Collection
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        if (! $org) {
            return collect();
        }

        return Workspace::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        $query = MarketplaceItem::query()
            ->active()
            ->category($this->category === 'all' ? null : $this->category)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($this->search !== '') {
            $needle = '%'.$this->search.'%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('summary', 'like', $needle);
            });
        }

        // The catalog carries the script-preset library too (167 rows), so the
        // grid pages rather than rendering every card at once.
        $items = $query->paginate(48);
        $catalogTotal = MarketplaceItem::query()->active()->count();
        $servers = $this->serversForCurrentOrg();
        $workspaces = $this->workspacesForCurrentOrg();
        $canImportWebserver = $org && $org->hasAdminAccess($user);
        // Scripts keep their own surface flag — hide the clone action when it is
        // off, otherwise the post-clone redirect lands on a 404 route.
        $canCloneScripts = $org !== null
            && feature('surface.scripts')
            && $user->can('create', Script::class);

        return view('livewire.marketplace.index', [
            'items' => $items,
            'catalogTotal' => $catalogTotal,
            'categories' => MarketplaceItem::categories(),
            'servers' => $servers,
            'workspaces' => $workspaces,
            'canImportWebserver' => $canImportWebserver,
            'canCloneScripts' => $canCloneScripts,
            'hasOrganization' => $org !== null,
        ]);
    }
}
