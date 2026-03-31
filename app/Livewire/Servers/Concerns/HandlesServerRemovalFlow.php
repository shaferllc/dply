<?php

namespace App\Livewire\Servers\Concerns;

use App\Actions\Servers\DeleteServerAction;
use App\Livewire\Concerns\ManagesServerRemovalForm;
use App\Services\Servers\ServerRemovalAdvisor;
use Carbon\Carbon;

trait HandlesServerRemovalFlow
{
    use ManagesServerRemovalForm;

    public bool $showRemoveServerModal = false;

    public string $deleteConfirmName = '';

    /** @var 'now'|'scheduled' */
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

        if (! hash_equals($server->name, trim($this->deleteConfirmName))) {
            $this->addError('deleteConfirmName', __('The name does not match exactly.'));

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
            $meta = $server->meta ?? [];
            if ($reason !== '') {
                $meta['scheduled_deletion_reason'] = $reason;
            } else {
                unset($meta['scheduled_deletion_reason']);
            }

            $org = $server->organization;
            if ($org) {
                $auditNew = [
                    'scheduled_deletion_at' => $at->toIso8601String(),
                ];
                if ($reason !== '') {
                    $auditNew['reason'] = $reason;
                }
                audit_log($org, auth()->user(), 'server.deletion_scheduled', $server, null, $auditNew);
            }

            $server->update([
                'scheduled_deletion_at' => $at,
                'meta' => $meta,
            ]);
            $this->notifyOrgAdminsOfScheduledRemoval($server->fresh(['organization']), $at, $reason !== '' ? $reason : null);
            $this->server = $server->fresh();
            $this->closeRemoveServerModal();
            session()->flash('success', __('This server is scheduled for removal at the end of :date.', [
                'date' => $at->toFormattedDateString(),
            ]));

            return null;
        }

        if (ServerRemovalAdvisor::hasRunningDeployments($server)) {
            $this->addError('removeMode', __('Finish or cancel running deployments on this server\'s sites before removing it.'));

            return null;
        }

        $summary = ServerRemovalAdvisor::summary($server);
        $this->validate($this->immediateServerRemovalRules($summary));

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

        return $this->redirect(route('servers.index'), navigate: true);
    }
}
