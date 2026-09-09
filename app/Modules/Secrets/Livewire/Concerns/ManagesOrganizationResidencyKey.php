<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Livewire\Concerns;

use App\Models\Organization;
use App\Models\OrgSecretKey;
use App\Modules\Secrets\Services\OrgSecretKeyManager;
use Livewire\Component;

/**
 * Rotate / revert / adopt the org age residency key (not the Secrets-tab vault).
 *
 * @phpstan-require-extends Component
 *
 * @property Organization $organization
 * @property-read OrgSecretKey|null $orgKey
 */
trait ManagesOrganizationResidencyKey
{
    /** A freshly-minted customer-held identity, shown exactly once after promote. */
    public ?string $revealed_identity = null;

    /** BYO recipient input for adopting a customer-supplied key. */
    public string $recipient_input = '';

    public function confirmRotateEncryptionKey(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);

        $key = $this->orgKey;
        if ($key === null) {
            return;
        }

        if ($key->identity_holder === OrgSecretKey::HOLDER_CUSTOMER) {
            $this->openConfirmActionModal(
                'rotateToNewCustomerHeldKey',
                [],
                __('Rotate customer-held key?'),
                __('dply will mint a new key and show the private identity once. The current key is discarded. Vault secrets on the Secrets tab are unaffected.'),
                __('Rotate key'),
                true,
                $this->residencyKeyChangeDetails($key, $manager),
                warning: $this->residencyKeyChangeWarning($manager),
            );

            return;
        }

        $this->openConfirmActionModal(
            'rotateToNewDplyHeldKey',
            [],
            __('Rotate dply-managed key?'),
            __('dply will mint a new managed key it can still decrypt. The current key is discarded. Vault secrets on the Secrets tab are unaffected.'),
            __('Rotate key'),
            true,
            $this->residencyKeyChangeDetails($key, $manager),
            warning: $this->residencyKeyChangeWarning($manager),
        );
    }

    public function confirmRevertToDplyHeld(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);

        $key = $this->orgKey;
        if ($key === null || $key->identity_holder !== OrgSecretKey::HOLDER_CUSTOMER) {
            return;
        }

        $this->openConfirmActionModal(
            'revertToDplyHeldKey',
            [],
            __('Revert to a dply-managed key?'),
            __('dply will mint a new key it holds. After this, dply can decrypt newly escrowed secrets. The current customer-held identity is discarded. Vault secrets on the Secrets tab are unaffected.'),
            __('Revert to dply-managed'),
            true,
            $this->residencyKeyChangeDetails($key, $manager),
            warning: $this->residencyKeyChangeWarning($manager),
        );
    }

    public function confirmPromoteToCustomerHeld(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);

        $key = $this->orgKey;
        if ($key === null) {
            $this->applyPromoteToCustomerHeld($manager);

            return;
        }

        $this->openConfirmActionModal(
            'applyPromoteToCustomerHeld',
            [],
            __('Switch to a customer-held key?'),
            __('dply will mint a new key and show the private identity once. After this, dply cannot decrypt escrowed secrets. Vault secrets on the Secrets tab are unaffected.'),
            __('Generate customer-held key'),
            true,
            $this->residencyKeyChangeDetails($key, $manager),
            warning: $this->residencyKeyChangeWarning($manager),
        );
    }

    public function rotateToNewCustomerHeldKey(OrgSecretKeyManager $manager): void
    {
        $this->applyPromoteToCustomerHeld($manager);
    }

    public function rotateToNewDplyHeldKey(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $manager->mintDplyHeld($this->organization->id);
        $this->forgetOrgKey();
        $this->toastSuccess(__('Rotated the dply-managed key. Newly escrowed secrets use the new recipient.'));
    }

    public function revertToDplyHeldKey(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $manager->mintDplyHeld($this->organization->id);
        $this->forgetOrgKey();
        $this->toastSuccess(__('Reverted to a dply-managed key. dply can decrypt newly escrowed secrets.'));
    }

    public function applyPromoteToCustomerHeld(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);

        $result = $manager->promoteToCustomerHeld($this->organization->id);
        $this->revealed_identity = $result['identity'];
        $this->forgetOrgKey();
        $this->toastSuccess(__('Generated a customer-held key. Save the identity now — dply does not keep a copy.'));
    }

    public function openAdoptRecipientModal(): void
    {
        $this->authorize('update', $this->organization);

        $this->reset('recipient_input');
        $this->resetValidation(['recipient_input']);
        $this->dispatch('open-modal', 'adopt-recipient-modal');
    }

    public function closeAdoptRecipientModal(): void
    {
        $this->reset('recipient_input');
        $this->resetValidation(['recipient_input']);
        $this->dispatch('close-modal', 'adopt-recipient-modal');
    }

    public function adoptRecipient(OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $this->validate(['recipient_input' => ['required', 'string', 'starts_with:age1']]);

        $recipient = trim($this->recipient_input);
        $key = $this->orgKey;
        if ($key !== null) {
            // Hand off to the confirm dialog — two stacked modals would fight
            // over the overlay, and this one has said all it has to say.
            $this->dispatch('close-modal', 'adopt-recipient-modal');

            $this->openConfirmActionModal(
                'applyAdoptRecipient',
                [$recipient],
                __('Replace this key with your recipient?'),
                __('dply will encrypt new escrowed secrets to this recipient and cannot decrypt them. The current key is discarded. Vault secrets on the Secrets tab are unaffected.'),
                __('Adopt recipient'),
                true,
                $this->residencyKeyChangeDetails($key, $manager),
                warning: $this->residencyKeyChangeWarning($manager),
            );

            return;
        }

        $this->applyAdoptRecipient($recipient, $manager);
    }

    public function applyAdoptRecipient(string $recipient, OrgSecretKeyManager $manager): void
    {
        $this->authorize('update', $this->organization);

        try {
            $manager->adoptCustomerRecipient($this->organization->id, $recipient);
        } catch (\Throwable $e) {
            // Re-open the form the confirm step closed, or the message lands on
            // a field nobody can see.
            $this->addError('recipient_input', $e->getMessage());
            $this->dispatch('open-modal', 'adopt-recipient-modal');

            return;
        }

        $this->reset('recipient_input');
        $this->dispatch('close-modal', 'adopt-recipient-modal');
        $this->forgetOrgKey();
        $this->toastSuccess(__('Adopted your recipient. dply can now encrypt to it but cannot decrypt — you hold the key.'));
    }

    public function dismissIdentity(): void
    {
        $this->revealed_identity = null;
    }

    /**
     * @return list<array{label: string, value: string, mono?: bool}>
     */
    private function residencyKeyChangeDetails(OrgSecretKey $key, OrgSecretKeyManager $manager): array
    {
        $count = $manager->escrowedResidencyCount($this->organization->id);

        return [
            [
                'label' => __('Current fingerprint'),
                'value' => $key->fingerprint ?: '—',
                'mono' => true,
            ],
            [
                'label' => __('Escrowed site secrets'),
                'value' => (string) $count,
            ],
        ];
    }

    private function residencyKeyChangeWarning(OrgSecretKeyManager $manager): ?string
    {
        $count = $manager->escrowedResidencyCount($this->organization->id);
        if ($count === 0) {
            return null;
        }

        return trans_choice(
            ':count escrowed site secret is encrypted to this key and will become unreadable until you re-move it.|:count escrowed site secrets are encrypted to this key and will become unreadable until you re-move them.',
            $count,
            ['count' => $count],
        );
    }

    private function forgetOrgKey(): void
    {
        unset($this->orgKey);
        $this->organization->unsetRelation('secretKey');
    }
}
