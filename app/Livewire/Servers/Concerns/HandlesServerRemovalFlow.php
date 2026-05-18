<?php

namespace App\Livewire\Servers\Concerns;

use App\Actions\Servers\DeleteServerAction;
use App\Livewire\Concerns\ManagesServerRemovalForm;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use Carbon\Carbon;

trait HandlesServerRemovalFlow
{
    use ManagesServerRemovalForm;

    public bool $showRemoveServerModal = false;

    public string $deleteConfirmName = '';

    /** @var 'now'|'in_30'|'scheduled' */
    public string $removeMode = 'now';

    public string $scheduledRemovalDate = '';

    public function openRemoveServerModal(): void
    {
        $this->authorize('delete', $this->server);
        $this->deleteConfirmName = '';
        $this->removeMode = 'now';
        $defaultDays = (int) config('dply.server_scheduled_deletion_default_days', 7);
        $this->scheduledRemovalDate = now()->addDays($defaultDays)->toDateString();
        $this->resetServerRemovalFormFields();
        $this->resetValidation();
        $this->showRemoveServerModal = true;
    }

    public function closeRemoveServerModal(): void
    {
        $this->showRemoveServerModal = false;
        $this->deleteConfirmName = '';
        $this->removeMode = 'now';
        $this->scheduledRemovalDate = '';
        $this->resetServerRemovalFormFields();
        $this->resetValidation();
    }

    public function submitRemoveServer(DeleteServerAction $deleteServer): mixed
    {
        $this->authorize('delete', $this->server);
        $server = $this->server->fresh();

        // Type-to-confirm is required for every mode — immediate, 30-min grace,
        // and far-future scheduling. The operator needs an explicit, name-matched
        // intent before the row is touched in any way.
        if (trim($this->deleteConfirmName) !== $server->name) {
            $this->addError('deleteConfirmName', __('Type the server name exactly to confirm.'));

            return null;
        }

        // 30-minute grace deletion: reuses the same scheduled-deletion path as
        // far-future scheduling, just with a precise near-term timestamp. The
        // every-minute ProcessScheduledServerDeletionsCommand picks it up
        // within a minute of the target. The operator can hit cancel from the
        // workspace anytime in the window — that's the point of the option.
        if ($this->removeMode === 'in_30') {
            $reason = trim($this->deletionReason);
            $at = now()->addMinutes(30);
            $this->persistScheduledRemoval($server, $at, $reason !== '' ? $reason : null);
            $this->server = $server->fresh();
            $this->closeRemoveServerModal();
            $this->toastSuccess(__('This server will be removed in 30 minutes. Cancel from here anytime before that.'));

            return null;
        }

        if ($this->removeMode === 'scheduled') {
            $this->validate([
                'scheduledRemovalDate' => ['required', 'date'],
                'deletionReason' => ['nullable', 'string', 'max:2000'],
            ]);
            $at = Carbon::parse($this->scheduledRemovalDate, config('app.timezone'))->endOfDay();
            if ($at->lte(now())) {
                $this->addError('scheduledRemovalDate', __('Pick a date whose end is still in the future (app timezone).'));

                return null;
            }

            $reason = trim($this->deletionReason);
            $this->persistScheduledRemoval($server, $at, $reason !== '' ? $reason : null);
            $this->server = $server->fresh();
            $this->closeRemoveServerModal();
            $this->toastSuccess(__('This server is scheduled for removal at the end of :date.', [
                'date' => $at->toFormattedDateString(),
            ]));

            return null;
        }

        if (ServerRemovalAdvisor::hasRunningDeployments($server)) {
            $this->addError('removeMode', __('Finish or cancel running deployments on this server\'s sites before removing it.'));

            return null;
        }

        $summary = ServerRemovalAdvisor::summary($server);
        $rules = $this->immediateServerRemovalRules($summary);
        if ($rules !== []) {
            $this->validate($rules);
        }

        $reason = trim($this->deletionReason);
        $auditExtras = ['immediate' => true];
        if ($reason !== '') {
            $auditExtras['reason'] = $reason;
        }

        $actor = auth()->user();
        $emailContext = __('Removed by :name (:email) from the server page.', [
            'name' => $actor->name,
            'email' => $actor->email,
        ]);

        $this->closeRemoveServerModal();
        $deleteServer->execute($server, $actor, $auditExtras, $emailContext);

        // Hard redirect (no navigate: true) — Livewire's SPA-style
        // soft navigation re-hydrates the current component before
        // the redirect URL takes over, and that re-hydration tries
        // to look up the just-deleted Server model → 404 modal. A
        // plain redirect avoids the round-trip entirely.
        return redirect()->route('servers.index');
    }

    /**
     * Shared "stamp scheduled_deletion_at + audit + notify org admins" path.
     * Used by both the 30-minute grace mode and the date-picker mode so the
     * two diverge only on the choice of $at.
     */
    private function persistScheduledRemoval(Server $server, Carbon $at, ?string $reason): void
    {
        $meta = $server->meta ?? [];
        if ($reason !== null && $reason !== '') {
            $meta['scheduled_deletion_reason'] = $reason;
        } else {
            unset($meta['scheduled_deletion_reason']);
        }

        $org = $server->organization;
        if ($org) {
            $auditNew = [
                'scheduled_deletion_at' => $at->toIso8601String(),
            ];
            if ($reason !== null && $reason !== '') {
                $auditNew['reason'] = $reason;
            }
            audit_log($org, auth()->user(), 'server.deletion_scheduled', $server, null, $auditNew);
        }

        $server->update([
            'scheduled_deletion_at' => $at,
            'meta' => $meta,
        ]);
        $this->notifyOrgAdminsOfScheduledRemoval($server->fresh(['organization']), $at, $reason !== '' ? $reason : null);
    }
}
