<?php

declare(strict_types=1);

namespace App\Modules\Cache\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ServiceCredential;
use App\Models\Site;
use App\Modules\Cache\Actions\AttachCacheToSite;
use App\Modules\Cache\Actions\DetachCacheFromSite;
use App\Modules\Cache\Actions\MintCacheCredential;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\PostgresCacheStore;
use App\Modules\Cache\Support\CacheEndpoint;
use App\Modules\Cache\Support\CacheWiring;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Livewire\Component;
use Throwable;

/**
 * One cache — usage against quota, credentials, attached sites, and the env
 * block to paste into an app that is not a dply site.
 *
 * The capability table this page renders is not decoration. `Cache::tags()`
 * throws on the shared tier because `DynamoDbStore` does not extend
 * `TaggableStore`, and `Cache::flush()` throws because DynamoDB cannot truncate
 * a table. Both are runtime failures a customer would otherwise meet in
 * production, so they are stated here rather than discovered.
 */
class CacheShow extends Component
{
    use DispatchesToastNotifications;

    public ManagedCache $managedCache;

    /** Attach-a-site picker. */
    public string $attachSiteId = '';

    public string $attachPrefix = '';

    /** Set for one render after an attach or a mint. Never re-shown. */
    public ?string $revealedSecret = null;

    public ?string $revealedEnvBlock = null;

    public ?string $revokingId = null;

    public bool $confirmingFlush = false;

    public function mount(ManagedCache $managedCache): void
    {
        $this->authorize('view', $managedCache);
        $this->managedCache = $managedCache;
    }

    public function mintCredential(): void
    {
        $this->authorize('manageCredentials', $this->managedCache);

        $minted = (new MintCacheCredential)->handle(
            $this->managedCache,
            __('Created :date', ['date' => now()->toDateString()]),
            userId: auth()->id(),
        );

        $this->revealedSecret = $minted['plaintext'];
        $this->revealedEnvBlock = CacheWiring::asEnvBlock(
            CacheWiring::envFor($this->managedCache, $minted['credential'], $minted['plaintext']),
        );

        $this->toastSuccess(__('Credential created. Copy it now — it is not shown again.'));
    }

    public function confirmRevoke(string $credentialId): void
    {
        $this->authorize('manageCredentials', $this->managedCache);
        $this->revokingId = $credentialId;
    }

    public function cancelRevoke(): void
    {
        $this->revokingId = null;
    }

    public function revokeCredential(): void
    {
        $this->authorize('manageCredentials', $this->managedCache);

        if ($this->revokingId === null) {
            return;
        }

        $credential = $this->managedCache->credentials()->whereKey($this->revokingId)->first();

        if (! $credential instanceof ServiceCredential) {
            $this->revokingId = null;

            return;
        }

        if (! $credential->isRevoked()) {
            $credential->forceFill(['revoked_at' => now()])->save();
        }

        // Exact eviction rather than waiting out a TTL — the property the
        // sha256-over-bcrypt choice exists to buy.
        CacheFacade::forget($credential->cacheKey());

        $this->revokingId = null;
        $this->toastSuccess(__('Credential revoked.'));
    }

    public function attach(AttachCacheToSite $action): void
    {
        $this->authorize('update', $this->managedCache);

        $this->validate([
            'attachSiteId' => ['required', 'string'],
            'attachPrefix' => ['nullable', 'string', 'max:64'],
        ]);

        $site = Site::query()
            ->where('organization_id', $this->managedCache->organization_id)
            ->find($this->attachSiteId);

        if (! $site instanceof Site) {
            $this->toastError(__('That site is not in this organization.'));

            return;
        }

        try {
            $env = $action->handle($this->managedCache, $site, $this->attachPrefix ?: null);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->revealedEnvBlock = CacheWiring::asEnvBlock($env);
        $this->attachSiteId = '';
        $this->attachPrefix = '';

        $this->toastSuccess(__('Attached. Redeploy the site for the new environment to take effect.'));
    }

    public function detach(string $siteId, DetachCacheFromSite $action): void
    {
        $this->authorize('update', $this->managedCache);

        $site = Site::query()->find($siteId);

        if ($site instanceof Site) {
            $action->handle($this->managedCache, $site);
            $this->toastSuccess(__('Detached. Redeploy the site to drop the old environment.'));
        }
    }

    public function confirmFlush(): void
    {
        $this->authorize('flush', $this->managedCache);
        $this->confirmingFlush = true;
    }

    public function cancelFlush(): void
    {
        $this->confirmingFlush = false;
    }

    /**
     * Empty the cache.
     *
     * Something the driver genuinely cannot do — `DynamoDbStore::flush()`
     * throws, because DynamoDB cannot truncate a table — but dply owns this
     * store, so the control plane can. See docs/adr/dply-cache.md, decision 11.
     */
    public function flush(PostgresCacheStore $store): void
    {
        $this->authorize('flush', $this->managedCache);

        $dropped = $store->flush($this->managedCache);

        $this->confirmingFlush = false;
        $this->toastSuccess(__('Flushed :count items.', ['count' => $dropped]));
    }

    public function dismissSecret(): void
    {
        $this->revealedSecret = null;
        $this->revealedEnvBlock = null;
    }

    public function render(PostgresCacheStore $store): View
    {
        $cache = $this->managedCache;
        $usage = $store->usage($cache->id);

        return view('livewire.caches.show', [
            'cache' => $cache,
            'usage' => $usage,
            'quotaBytes' => $cache->quotaBytes(),
            'quotaFraction' => $usage->fractionOf($cache->quotaBytes()),
            'credentials' => $cache->isShared()
                ? $cache->credentials()->orderByDesc('created_at')->get()
                : collect(),
            'attachedSites' => $cache->sites()->get(),
            'attachableSites' => Site::query()
                ->where('organization_id', $cache->organization_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'endpoint' => CacheEndpoint::base(),
            // Rendered rather than composed in the view so the tier branch
            // lives in one place. Secrets are the cluster's own password,
            // which dply already stores and can re-show — unlike a shared
            // cache's credential, which is hashed.
            'dedicatedEnvPreview' => $cache->isShared()
                ? ''
                : CacheWiring::asEnvBlock(CacheWiring::envFor($cache, null, null)),
            'canManage' => auth()->user()?->can('update', $cache) ?? false,
            'canManageCredentials' => auth()->user()?->can('manageCredentials', $cache) ?? false,
            'canFlush' => auth()->user()?->can('flush', $cache) ?? false,
            'breadcrumbs' => [
                ['label' => __('Services'), 'url' => null],
                ['label' => __('Caches'), 'url' => route('caches.index')],
                ['label' => $cache->name, 'url' => null],
            ],
        ]);
    }
}
