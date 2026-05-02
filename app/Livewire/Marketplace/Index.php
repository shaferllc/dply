<?php

namespace App\Livewire\Marketplace;

use App\Models\MarketplaceItem;
use App\Models\Server;
use App\Models\User;
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
    #[Url(history: true)]
    public string $category = 'all';

    #[Url(history: true)]
    public string $search = '';

    public ?string $deployModalItemId = null;

    public ?string $serverRecipeModalItemId = null;

    public ?string $deployServerId = null;

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

    public function closeServerImportModal(): void
    {
        $this->deployModalItemId = null;
        $this->serverRecipeModalItemId = null;
        $this->deployServerId = null;
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

        $this->closeServerImportModal();
        $this->toastSuccess(__('Saved command imported to :server.', ['server' => $server->name]));
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
        $canImportWebserver = $org && $org->hasAdminAccess($user);

        return view('livewire.marketplace.index', [
            'items' => $items,
            'categories' => MarketplaceItem::categories(),
            'servers' => $servers,
            'canImportWebserver' => $canImportWebserver,
            'hasOrganization' => $org !== null,
        ]);
    }
}
