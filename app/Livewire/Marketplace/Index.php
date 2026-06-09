<?php

namespace App\Livewire\Marketplace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\MarketplaceItem;
use App\Models\Server;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Marketplace\MarketplaceImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use RequiresFeature;

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

    public function updatedCategory(string $value): void
    {
        if ($value !== 'all' && ! array_key_exists($value, MarketplaceItem::categories())) {
            $this->category = 'all';
        }
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

        $orgId = $user->currentOrganization()?->id;
        if (! $orgId) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }

        $server = Server::query()
            ->where('organization_id', $orgId)
            ->whereKey($this->deployServerId)
            ->firstOrFail();

        $this->authorize('update', $server);

        $importService->importDeployCommand($user, $item, $server);

        if ($org = $user->currentOrganization()) {
            audit_log($org, $user, 'marketplace.deploy_command_imported', $item, null, [
                'item_id' => (string) $item->id,
                'item_name' => $item->name,
                'server_id' => (string) $server->id,
                'server_name' => $server->name,
            ]);
        }

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

        $orgId = $user->currentOrganization()?->id;
        if (! $orgId) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }

        $server = Server::query()
            ->where('organization_id', $orgId)
            ->whereKey($this->deployServerId)
            ->firstOrFail();

        $this->authorize('update', $server);

        $importService->importServerRecipe($user, $item, $server);

        if ($org = $user->currentOrganization()) {
            audit_log($org, $user, 'marketplace.server_recipe_imported', $item, null, [
                'item_id' => (string) $item->id,
                'item_name' => $item->name,
                'server_id' => (string) $server->id,
                'server_name' => $server->name,
            ]);
        }

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

        $orgId = $user->currentOrganization()?->id;
        if (! $orgId) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }

        $workspace = Workspace::query()
            ->where('organization_id', $orgId)
            ->whereKey($this->runbookWorkspaceId)
            ->firstOrFail();

        $this->authorize('update', $workspace);

        $runbook = $importService->importWorkspaceRunbook($user, $item, $workspace);

        if ($org = $user->currentOrganization()) {
            audit_log($org, $user, 'marketplace.workspace_runbook_imported', $item, null, [
                'item_id' => (string) $item->id,
                'item_name' => $item->name,
                'workspace_id' => (string) $workspace->id,
                'workspace_name' => $workspace->name,
                'runbook_id' => (string) $runbook->id,
            ]);
        }

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

        $items = $query->get();
        $servers = $this->serversForCurrentOrg();
        $workspaces = $this->workspacesForCurrentOrg();
        $canImportWebserver = $org && $org->hasAdminAccess($user);

        return view('livewire.marketplace.index', [
            'items' => $items,
            'categories' => MarketplaceItem::categories(),
            'servers' => $servers,
            'workspaces' => $workspaces,
            'canImportWebserver' => $canImportWebserver,
            'hasOrganization' => $org !== null,
        ]);
    }
}
