<?php

declare(strict_types=1);

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ExternalSecretStore;
use App\Models\Organization;
use App\Models\OrgSecretKey;
use App\Services\Secrets\OrgSecretKeyManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-level management for the secret-residency key model: the organization's
 * encryption key (dply-held vs customer-held) and any external secret stores
 * (Vault / AWS Secrets Manager / Doppler) sites can reference. The per-key
 * escrow controls live on each site's Environment tab; this is where the org
 * decides WHO holds the key and WHICH external stores are available.
 */
#[Layout('layouts.app')]
class Secrets extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /** A freshly-minted customer-held identity, shown exactly once after promote. */
    public ?string $revealed_identity = null;

    /** BYO recipient input for adopting a customer-supplied key. */
    public string $recipient_input = '';

    /** New external store form. */
    public string $store_driver = ExternalSecretStore::DRIVER_VAULT;

    public string $store_name = '';

    public string $store_resolution = ExternalSecretStore::RESOLUTION_DPLY;

    /** @var array<string, string> driver-shaped connection config */
    public array $store_form = [];

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
    }

    public function promoteToCustomerHeld(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);

        $result = $manager->promoteToCustomerHeld($this->organization->id);
        $this->revealed_identity = $result['identity'];
        $this->toastSuccess(__('Generated a customer-held key. Save the identity now — dply does not keep a copy.'));
    }

    public function adoptRecipient(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $this->validate(['recipient_input' => ['required', 'string', 'starts_with:age1']]);

        try {
            $manager->adoptCustomerRecipient($this->organization->id, trim($this->recipient_input));
        } catch (\Throwable $e) {
            $this->addError('recipient_input', $e->getMessage());

            return;
        }

        $this->reset('recipient_input');
        $this->toastSuccess(__('Adopted your recipient. dply can now encrypt to it but cannot decrypt — you hold the key.'));
    }

    public function dismissIdentity(): void
    {
        $this->revealed_identity = null;
    }

    public function createStore(): void
    {
        $this->authorize('update', $this->organization);
        $this->validate([
            'store_driver' => ['required', 'in:'.implode(',', ExternalSecretStore::DRIVERS)],
            'store_name' => ['required', 'string', 'max:120'],
            'store_resolution' => ['required', 'in:'.ExternalSecretStore::RESOLUTION_DPLY.','.ExternalSecretStore::RESOLUTION_ONBOX],
        ]);

        ExternalSecretStore::create([
            'organization_id' => $this->organization->id,
            'driver' => $this->store_driver,
            'name' => $this->store_name,
            'config' => array_filter($this->store_form, fn ($v): bool => $v !== null && $v !== ''),
            'resolution' => $this->store_resolution,
        ]);

        $this->reset('store_name', 'store_form');
        $this->toastSuccess(__('External secret store added.'));
    }

    public function deleteStore(string $storeId): void
    {
        $this->authorize('update', $this->organization);

        ExternalSecretStore::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($storeId)
            ->delete();

        $this->toastSuccess(__('External secret store removed.'));
    }

    /** @return Collection<int, ExternalSecretStore> */
    public function getStoresProperty(): Collection
    {
        return ExternalSecretStore::query()
            ->where('organization_id', $this->organization->id)
            ->orderBy('name')
            ->get();
    }

    public function getOrgKeyProperty(): ?OrgSecretKey
    {
        return $this->organization->secretKey()->first();
    }

    public function render(): View
    {
        return view('livewire.organizations.secrets', [
            'orgKey' => $this->orgKey,
            'stores' => $this->stores,
        ]);
    }
}
