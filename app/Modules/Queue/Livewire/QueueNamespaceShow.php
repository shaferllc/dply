<?php

declare(strict_types=1);

namespace App\Modules\Queue\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Billing\Services\QueueUsageCostCalculator;
use App\Modules\Queue\Actions\DeleteQueueNamespace;
use App\Modules\Queue\Actions\RevokeQueueCredential;
use App\Modules\Queue\Actions\RotateQueueCredential;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueUsageMeter;
use App\Modules\Queue\Support\QueueEntitlements;
use App\Policies\QueueNamespacePolicy;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * One dply Queue namespace: its endpoint, what an app puts in `.env` to reach
 * it, live depth, month-to-date volume, and its credentials.
 *
 * Credentials are the reason this page exists separately from the list. A queue
 * credential can drain a production queue, so minting and revoking are gated on
 * admin access ({@see QueueNamespacePolicy::manageCredentials()})
 * and the plaintext is shown exactly once — dply keeps an encrypted copy
 * because SigV4 must recompute the HMAC, but showing it again would turn a
 * database read into a credential disclosure.
 *
 * Rotation is two-step by design, not by omission: a credential lives in a
 * `.env` that only reaches the running app on its next deploy, so mint-then-
 * revoke is the only sequence that does not guarantee an outage. `last_used_at`
 * on the old credential is what tells the operator the redeploy has landed.
 */
#[Layout('layouts.app')]
class QueueNamespaceShow extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    public QueueNamespace $queueNamespace;

    /** Name for the credential about to be minted. */
    public string $credentialName = '';

    /**
     * The one and only time a secret is displayed. Held in component state for
     * this render only — never persisted, never re-derivable from the page.
     */
    public ?string $revealedSecret = null;

    public ?string $revealedCredentialId = null;

    /** The credential open in the revoke-confirmation modal, if any. */
    public ?string $revokingCredentialId = null;

    public bool $confirmingDelete = false;

    public string $deleteConfirmation = '';

    public function mount(Organization $organization, QueueNamespace $queueNamespace): void
    {
        $this->authorize('view', $organization);
        abort_unless($queueNamespace->organization_id === $organization->id, 404);

        $this->organization = $organization;
        $this->queueNamespace = $queueNamespace;

        // Handed over by the create flow, which minted the first credential
        // and has nowhere else to show it.
        $flashed = session('queue.revealed_secret');

        if (is_string($flashed) && $flashed !== '') {
            $this->revealedSecret = $flashed;
            $this->revealedCredentialId = (string) session('queue.revealed_credential_id');
        }
    }

    public function startMint(): void
    {
        $this->authorize('manageCredentials', $this->queueNamespace);

        $this->credentialName = '';
        $this->dispatch('open-modal', 'queue-credential-modal');
    }

    public function cancelMint(): void
    {
        $this->reset('credentialName');
        $this->dispatch('close-modal', 'queue-credential-modal');
    }

    /**
     * Mint a second live credential so the app can be redeployed onto it
     * before the old one is revoked.
     */
    public function mintCredential(RotateQueueCredential $action): void
    {
        $this->authorize('manageCredentials', $this->queueNamespace);

        try {
            // Enforces the two-live-credential cap — a third would mean an
            // earlier rotation was abandoned, leaving a secret live forever.
            $minted = $action->handle(
                $this->queueNamespace,
                trim($this->credentialName) !== '' ? trim($this->credentialName) : null,
                auth()->id(),
            );
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->revealedSecret = $minted['plaintext'];
        $this->revealedCredentialId = $minted['credential']->id;

        $this->cancelMint();

        $this->toastSuccess(__('Credential minted. Copy the secret now — it is not shown again.'));
    }

    public function dismissSecret(): void
    {
        $this->reset(['revealedSecret', 'revealedCredentialId']);
    }

    public function confirmRevoke(string $credentialId): void
    {
        $this->authorize('manageCredentials', $this->queueNamespace);

        $this->revokingCredentialId = $this->findCredential($credentialId)->id;
        $this->dispatch('open-modal', 'queue-revoke-modal');
    }

    public function cancelRevoke(): void
    {
        $this->reset('revokingCredentialId');
        $this->dispatch('close-modal', 'queue-revoke-modal');
    }

    public function revokeCredential(RevokeQueueCredential $action): void
    {
        $this->authorize('manageCredentials', $this->queueNamespace);

        $credential = $this->findCredential((string) $this->revokingCredentialId);
        $action->handle($credential);

        $this->cancelRevoke();

        $this->toastSuccess(__('Credential revoked. Any app still using it will start failing immediately.'));
    }

    public function confirmDelete(): void
    {
        $this->confirmingDelete = true;
        $this->deleteConfirmation = '';
        $this->dispatch('open-modal', 'queue-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->reset('deleteConfirmation');
        $this->dispatch('close-modal', 'queue-delete-modal');
    }

    public function deleteNamespace(DeleteQueueNamespace $action)
    {
        $this->authorize('delete', $this->queueNamespace);

        if (trim($this->deleteConfirmation) !== $this->queueNamespace->name) {
            $this->toastError(__('Type the queue’s name to confirm.'));

            return null;
        }

        $action->handle($this->queueNamespace);

        $this->toastSuccess(__('Queue deleted.'));

        return $this->redirect(route('organizations.queues', $this->organization), navigate: true);
    }

    public function togglePause(): void
    {
        $this->authorize('update', $this->queueNamespace);

        $paused = $this->queueNamespace->status === QueueNamespace::STATUS_PAUSED;

        $this->queueNamespace->forceFill([
            'status' => $paused ? QueueNamespace::STATUS_ACTIVE : QueueNamespace::STATUS_PAUSED,
        ])->save();

        $this->queueNamespace->refresh();

        $this->toastSuccess($paused
            ? __('Queue resumed — it is accepting pushes again.')
            : __('Queue paused. New pushes are rejected; anything already queued still drains.'));
    }

    /** Resolve a credential id, scoped to this namespace (404 otherwise). */
    private function findCredential(string $credentialId): QueueCredential
    {
        return $this->queueNamespace->credentials()->findOrFail($credentialId);
    }

    /** The base URL an app's queue client posts to. */
    private function endpoint(): string
    {
        $configured = trim((string) config('queue_service.public_url', ''));

        if ($configured === '') {
            $public = trim((string) config('dply.public_app_url', ''));

            if ($public === '') {
                return '';
            }

            if (preg_match('~^https?://~i', $public) !== 1) {
                $public = 'https://'.$public;
            }

            $configured = rtrim($public, '/').'/api/queue/v1';
        }

        return rtrim($configured, '/').'/'.$this->queueNamespace->id;
    }

    public function render(
        QueueStore $store,
        QueueUsageMeter $meter,
        QueueEntitlements $entitlements,
        QueueUsageCostCalculator $cost,
    ): View {
        $depth = null;

        try {
            $depth = $store->depth($this->queueNamespace)->toArray();
        } catch (Throwable $e) {
            // Separate connection, separate failure modes — see Queues::render().
        }

        $entitlement = $entitlements->for($this->organization);
        $usedJobs = $meter->monthToDateJobs($this->organization);

        $credentials = $this->queueNamespace->credentials()
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.organizations.queue-namespace', [
            'endpoint' => $this->endpoint(),
            'depth' => $depth,
            'credentials' => $credentials,
            'liveCredentialCount' => $credentials->filter(
                fn (QueueCredential $credential): bool => $credential->isUsable()
            )->count(),
            'maxLiveCredentials' => RotateQueueCredential::MAX_LIVE_CREDENTIALS,
            'entitlement' => $entitlement,
            'usedJobs' => $usedJobs,
            'overIncluded' => $cost->isOverIncluded($entitlement, $usedJobs),
            'billingLive' => $cost->isEnabled(),
            'canManageCredentials' => auth()->user()?->can('manageCredentials', $this->queueNamespace) ?? false,
            'canUpdate' => auth()->user()?->can('update', $this->queueNamespace) ?? false,
            'canDelete' => auth()->user()?->can('delete', $this->queueNamespace) ?? false,
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Queues'), 'href' => route('organizations.queues', $this->organization), 'icon' => 'queue-list'],
                ['label' => $this->queueNamespace->name, 'icon' => 'queue-list'],
            ],
            'orgShellSection' => 'queues',
        ]);
    }
}
