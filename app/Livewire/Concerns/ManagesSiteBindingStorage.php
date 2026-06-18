<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ObjectStorageCredential;
use App\Models\ProviderCredential;
use App\Models\SiteBinding;
use App\Services\Deploy\SiteBindingManager;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteBindingStorage
{


    /**
     * Cloud API-token credentials the org holds for a storage provider's
     * api_provider (e.g. digitalocean), powering the auto-create flow + picker.
     *
     * @return list<array{id: string, label: string}>
     */
    public function cloudCredentialsForStorage(string $storageProvider): array
    {
        $apiProvider = (string) config('object_storage.providers.'.$storageProvider.'.api_provider', '');
        if ($apiProvider === '') {
            return [];
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $apiProvider)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProviderCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) ($c->name ?: ucfirst($apiProvider).' token'),
            ])
            ->all();
    }

    /**
     * The filesystem-disk slug a storage binding maps to. Legacy rows stored the
     * bucket in `name` with no `config['disk']`; those are the primary `s3` disk.
     */
    public function storageDiskLabel(SiteBinding $binding): string
    {
        return (string) (((array) $binding->config)['disk'] ?? 's3');
    }

    /**
     * The human bucket name behind a storage binding (falls back to the row name).
     */
    public function storageBucketLabel(SiteBinding $binding): string
    {
        return (string) (((array) $binding->config)['bucket'] ?? $binding->name ?? '');
    }

    /**
     * The `config/filesystems.php` disk array to paste into the app for a
     * non-primary storage disk (empty for the primary `s3` disk).
     */
    public function storageDiskSnippet(SiteBinding $binding): string
    {
        return app(SiteBindingManager::class)->storageFilesystemSnippet($this->storageDiskLabel($binding));
    }

    /** Normalize a typed disk name to its slug, for live preview in the modal. */
    public function storageDiskSlugPreview(mixed $name): string
    {
        return app(SiteBindingManager::class)->storageDiskSlug($name);
    }

    /** Live `config/filesystems.php` snippet for the disk name being typed. */
    public function storageSnippetForDiskName(mixed $name): string
    {
        $manager = app(SiteBindingManager::class);

        return $manager->storageFilesystemSnippet($manager->storageDiskSlug($name));
    }

    /**
     * Saved object-storage credentials the site's org can reuse for $provider,
     * for the binding modal's "Use saved keys" picker.
     *
     * @return list<array{id: string, label: string, region: string, endpoint: string}>
     */
    public function storageCredentialsFor(string $provider): array
    {
        return ObjectStorageCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectStorageCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
                'region' => (string) ($c->region ?? ''),
                'endpoint' => (string) ($c->endpoint ?? ''),
            ])
            ->all();
    }

    public function deleteStorageCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = ObjectStorageCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof ObjectStorageCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved storage credential removed.'));
    }
}
