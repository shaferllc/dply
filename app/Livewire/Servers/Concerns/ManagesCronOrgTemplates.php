<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\OrganizationCronJobTemplate;
use Carbon\Carbon;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronOrgTemplates
{
    public ?string $org_maintenance_until_local = null;

    public string $org_maintenance_note = '';

    public ?string $template_save_name = null;

    public function saveOrgCronTemplate(): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);

        $this->validate([
            'template_save_name' => ['required', 'string', 'max:120'],
            'new_cron_expression' => ['required', 'string', 'max:64'],
            'new_cron_command' => 'required|string|max:2000',
            'new_cron_user' => ['required', 'string', 'max:64'],
        ]);

        OrganizationCronJobTemplate::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'name' => trim($this->template_save_name),
            ],
            [
                'cron_expression' => trim($this->new_cron_expression),
                'command' => trim($this->new_cron_command),
                'user' => trim($this->new_cron_user),
                'description' => trim((string) $this->new_description) ?: null,
            ]
        );
        $this->template_save_name = null;
        $this->toastSuccess(__('Template saved for this organization.'));
    }

    public function deleteOrgCronTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);
        OrganizationCronJobTemplate::query()
            ->where('organization_id', $org->id)
            ->whereKey($templateId)
            ->firstOrFail()
            ->delete();
        $this->toastSuccess(__('Template removed.'));
    }

    public function applyOrgCronTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $tpl = OrganizationCronJobTemplate::query()
            ->where('organization_id', $org->id)
            ->whereKey($templateId)
            ->firstOrFail();
        $this->editing_job_id = null;
        $this->new_cron_expression = $tpl->cron_expression;
        $this->new_cron_command = $tpl->command;
        $this->new_cron_user = $tpl->user;
        $this->new_description = $tpl->description;
        $this->command_preset = 'custom';
        $this->updatedNewCronExpression();
        $this->cron_workspace_tab = 'jobs';
        $this->dispatch('open-modal', 'add-cron-job-modal');
        $this->toastSuccess(__('Loaded template into the form — review and save.'));
    }

    public function saveOrgCronMaintenance(): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);

        $this->validate([
            'org_maintenance_until_local' => ['nullable', 'string', 'max:32'],
            'org_maintenance_note' => ['nullable', 'string', 'max:500'],
        ]);

        $until = null;
        if ($this->org_maintenance_until_local !== null && trim($this->org_maintenance_until_local) !== '') {
            try {
                $until = Carbon::parse($this->org_maintenance_until_local, config('app.timezone'));
            } catch (\Throwable) {
                $this->addError('org_maintenance_until_local', __('Invalid date/time.'));

                return;
            }
        }

        $org->update([
            'cron_maintenance_until' => $until,
            'cron_maintenance_note' => trim($this->org_maintenance_note) ?: null,
        ]);
        $this->toastSuccess(__('Maintenance window saved. Managed cron lines are omitted until then.'));
    }

    public function clearOrgCronMaintenance(): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);
        $org->update([
            'cron_maintenance_until' => null,
            'cron_maintenance_note' => null,
        ]);
        $this->org_maintenance_until_local = null;
        $this->org_maintenance_note = '';
        $this->toastSuccess(__('Maintenance window cleared.'));
    }
}
