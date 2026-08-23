<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Livewire;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\OrgSecretKey;
use App\Modules\Secrets\Livewire\Concerns\ManagesOrganizationResidencyKey;
use App\Modules\Secrets\Livewire\Concerns\ManagesOrganizationVaultSecrets;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org Secrets: Cloud-style vault (Secrets tab) plus residency (the org age key).
 * Vault values are write-never after save.
 *
 * Livewire exposes get<Name>Property() methods as $this-><name> in PHP and
 * Blade. PHPStan cannot see that magic, so the contract is stated here.
 *
 * @property-read OrgSecretKey|null $orgKey
 */
#[Layout('layouts.app')]
class Secrets extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesOrganizationResidencyKey;
    use ManagesOrganizationVaultSecrets;

    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
    }

    public function getOrgKeyProperty(): ?OrgSecretKey
    {
        return $this->organization->secretKey()->first();
    }

    public function render(): View
    {
        return view('livewire.organizations.secrets', [
            'orgKey' => $this->orgKey,
            'vaultRows' => $this->vaultSecretRows(),
        ]);
    }
}
