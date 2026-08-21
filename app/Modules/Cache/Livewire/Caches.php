<?php

declare(strict_types=1);

namespace App\Modules\Cache\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Cache\Actions\CreateManagedCache;
use App\Modules\Cache\Actions\DeleteManagedCache;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\PostgresCacheStore;
use App\Modules\Cache\Support\CacheEndpoint;
use App\Modules\Cache\Support\CacheEntitlements;
use App\Modules\Cache\Support\CacheUsage;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * The dply Cache index — the org's caches, what they hold, and how close each
 * is to its quota.
 *
 * Session-scoped like every other product index; the route group gates on
 * `surface.cache`, so the off-state is a 404 and this only runs when Cache is
 * live for the org.
 */
class Caches extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /** Create modal. */
    public bool $creating = false;

    public string $createName = '';

    /** The cache open in the delete-confirmation modal, if any. */
    public ?string $deletingId = null;

    /** Must match the cache's name before a delete is allowed through. */
    public string $deleteConfirmation = '';

    /**
     * The one-time secret, held only for the render immediately after a
     * create. Never persisted to the component's state beyond that: a secret
     * living in a Livewire snapshot is a secret in the page source on every
     * subsequent request.
     */
    public ?string $revealedSecret = null;

    public ?string $revealedCacheId = null;

    public function mount(): void
    {
        $organization = auth()->user()?->currentOrganization();
        abort_if($organization === null, 403);

        $this->organization = $organization;
        $this->authorize('viewAny', ManagedCache::class);
    }

    public function startCreate(): void
    {
        $this->authorize('create', ManagedCache::class);
        $this->createName = '';
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
        $this->createName = '';
    }

    public function create(CreateManagedCache $action): void
    {
        $this->authorize('create', ManagedCache::class);

        $this->validate(['createName' => ['required', 'string', 'max:60']]);

        try {
            $result = $action->handle($this->organization, $this->createName, auth()->id());
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->creating = false;
        $this->createName = '';

        // Shown once, then gone — dply stores a hash, so it cannot be
        // re-revealed later and the panel must say so rather than pretend.
        $this->revealedSecret = $result['plaintext'];
        $this->revealedCacheId = $result['cache']->id;

        $this->toastSuccess(__('Cache created. Copy the secret now — it is not shown again.'));
    }

    public function dismissSecret(): void
    {
        $this->revealedSecret = null;
        $this->revealedCacheId = null;
    }

    public function confirmDelete(string $cacheId): void
    {
        $cache = $this->cache($cacheId);
        $this->authorize('delete', $cache);

        $this->deletingId = $cache->id;
        $this->deleteConfirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deleteConfirmation = '';
    }

    public function destroy(DeleteManagedCache $action): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $cache = $this->cache($this->deletingId);
        $this->authorize('delete', $cache);

        // Typing the name is the guard. A cache holds live locks as well as
        // values, so deleting the wrong one does not merely lose data — it
        // releases whatever mutexes another app is currently relying on.
        if (trim($this->deleteConfirmation) !== $cache->name) {
            $this->toastError(__('Type the cache name exactly to confirm.'));

            return;
        }

        $result = $action->handle($cache);

        $this->cancelDelete();
        $this->toastSuccess(__('Cache deleted — :items items dropped.', ['items' => $result['items']]));
    }

    private function cache(string $id): ManagedCache
    {
        return ManagedCache::query()
            ->where('organization_id', $this->organization->id)
            ->findOrFail($id);
    }

    public function render(PostgresCacheStore $store): View
    {
        $caches = ManagedCache::query()
            ->where('organization_id', $this->organization->id)
            ->orderBy('name')
            ->get();

        // One query for every meter on the page rather than one per row.
        $usage = $store->usageFor($caches->pluck('id')->all());

        $entitlement = app(CacheEntitlements::class)->for($this->organization);

        return view('livewire.caches.index', [
            'caches' => $caches,
            'usage' => $usage,
            'emptyUsage' => CacheUsage::empty(),
            'entitlement' => $entitlement,
            'atLimit' => ! $entitlement->allowsAnother($caches->count()),
            'canManage' => auth()->user()?->can('create', ManagedCache::class) ?? false,
            'endpoint' => CacheEndpoint::base(),
            'breadcrumbs' => [
                ['label' => __('Services'), 'url' => null],
                ['label' => __('Caches'), 'url' => null],
            ],
        ]);
    }
}
