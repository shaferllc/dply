<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerCronJob;
use App\Models\Site;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\ServerCronSynchronizer;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronJobs
{
    public ?string $editing_job_id = null;

    public string $new_cron_expression = '* * * * *';

    public string $new_cron_command = '';

    public string $new_cron_user = '';

    public ?string $new_description = null;

    public ?string $new_site_id = null;

    public ?string $new_schedule_timezone = null;

    public string $new_overlap_policy = ServerCronJob::OVERLAP_ALLOW;

    public bool $new_alert_on_failure = false;

    public bool $new_alert_on_pattern_match = false;

    public ?string $new_alert_pattern = null;

    public ?string $new_env_prefix = null;

    public ?string $new_depends_on_job_id = null;

    public ?string $new_maintenance_tag = null;

    public function updatedNewCronExpression(): void
    {
        $expr = trim($this->new_cron_expression);
        foreach ($this->presetExpressions as $key => $preset) {
            if ($key !== 'custom' && $preset === $expr) {
                $this->frequency_preset = $key;

                return;
            }
        }
        $this->frequency_preset = 'custom';
    }

    public function startEdit(string $jobId): void
    {
        $this->authorize('update', $this->server);
        $job = ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($jobId);
        if ($job->system_managed) {
            $this->toastError(__('This cron line is managed automatically by Dply and can\'t be edited from here.'));

            return;
        }
        $this->editing_job_id = $job->id;
        $this->new_cron_expression = $job->cron_expression;
        $this->new_cron_command = $job->command;
        $this->new_cron_user = $job->user;
        $this->new_description = $job->description;
        $this->new_site_id = $job->site_id;
        $this->command_preset = 'custom';
        $this->new_schedule_timezone = $job->schedule_timezone ?? config('app.timezone');
        $this->new_overlap_policy = $job->overlap_policy ?? ServerCronJob::OVERLAP_ALLOW;
        $this->new_alert_on_failure = (bool) $job->alert_on_failure;
        $this->new_alert_on_pattern_match = (bool) $job->alert_on_pattern_match;
        $this->new_alert_pattern = $job->alert_pattern;
        $this->new_env_prefix = $job->env_prefix;
        $this->new_depends_on_job_id = $job->depends_on_job_id;
        $this->new_maintenance_tag = $job->maintenance_tag;
        $this->updatedNewCronExpression();
        $this->cron_workspace_tab = 'jobs';
    }

    public function cancelEdit(): void
    {
        $this->editing_job_id = null;
        $this->resetForm();
    }

    public function saveCronJob(CronExpressionValidator $cronValidator): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_cron_expression' => ['required', 'string', 'max:64', 'regex:/^(\S+\s+){4}\S+$/'],
            'new_cron_command' => 'required|string|max:2000',
            'new_cron_user' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'new_description' => 'nullable|string|max:500',
            'new_site_id' => ['nullable', 'ulid', Rule::exists('sites', 'id')->where('server_id', $this->server->id)],
            'new_schedule_timezone' => ['nullable', 'string', 'max:64'],
            'new_overlap_policy' => ['required', 'string', Rule::in([ServerCronJob::OVERLAP_ALLOW, ServerCronJob::OVERLAP_SKIP_IF_RUNNING])],
            'new_alert_pattern' => ['nullable', 'string', 'max:512'],
            'new_env_prefix' => ['nullable', 'string', 'max:4000'],
            'new_depends_on_job_id' => ['nullable', 'ulid', Rule::exists('server_cron_jobs', 'id')->where('server_id', $this->server->id)],
            'new_maintenance_tag' => ['nullable', 'string', 'max:64'],
        ], [
            'new_cron_expression.regex' => __('Use five cron fields (minute hour day month weekday).'),
            'new_cron_user.regex' => __('Use a valid Linux username.'),
        ]);

        if (! $cronValidator->isValid(trim($this->new_cron_expression))) {
            $this->addError('new_cron_expression', __('This cron expression is not valid.'));

            return;
        }

        if ($this->new_alert_pattern !== null && trim($this->new_alert_pattern) !== '') {
            set_error_handler(static fn () => true);
            $ok = @preg_match($this->new_alert_pattern, '') !== false;
            restore_error_handler();
            if (! $ok) {
                $this->addError('new_alert_pattern', __('Enter a valid PCRE pattern (include delimiters), e.g. /error/i'));

                return;
            }
        }

        if ($this->editing_job_id && $this->new_depends_on_job_id === $this->editing_job_id) {
            $this->addError('new_depends_on_job_id', __('A job cannot depend on itself.'));

            return;
        }

        $payload = [
            'cron_expression' => trim($this->new_cron_expression),
            'command' => trim($this->new_cron_command),
            'user' => trim($this->new_cron_user),
            'description' => trim((string) $this->new_description) ?: null,
            'site_id' => $this->new_site_id ?: null,
            'is_synced' => false,
            'schedule_timezone' => trim((string) ($this->new_schedule_timezone ?? '')) ?: null,
            'overlap_policy' => $this->new_overlap_policy,
            'alert_on_failure' => $this->new_alert_on_failure,
            'alert_on_pattern_match' => $this->new_alert_on_pattern_match,
            'alert_pattern' => trim((string) ($this->new_alert_pattern ?? '')) ?: null,
            'env_prefix' => trim((string) ($this->new_env_prefix ?? '')) ?: null,
            'depends_on_job_id' => $this->new_depends_on_job_id ?: null,
            'maintenance_tag' => trim((string) ($this->new_maintenance_tag ?? '')) ?: null,
        ];

        if ($this->editing_job_id) {
            $job = ServerCronJob::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->editing_job_id)
                ->firstOrFail();
            $oldSnapshot = [
                'cron_expression' => $job->cron_expression,
                'command' => $job->command,
                'user' => $job->user,
                'description' => $job->description,
                'site_id' => $job->site_id,
                'overlap_policy' => $job->overlap_policy,
                'alert_on_failure' => (bool) $job->alert_on_failure,
                'schedule_timezone' => $job->schedule_timezone,
            ];
            $job->update($payload);
            audit_log(
                $this->server->organization,
                auth()->user(),
                'server.cron.updated',
                $this->server,
                $oldSnapshot,
                array_merge(
                    array_intersect_key($payload, $oldSnapshot),
                    ['cron_job_id' => (string) $job->id],
                ),
            );
            $this->emitPanelEvent(
                __('Cron job updated — sync to apply'),
                array_filter([
                    sprintf('> Updated "%s" in the panel.', $job->fresh()->description ?: $payload['command']),
                    sprintf('  schedule: %s · user: %s', $payload['cron_expression'], $payload['user']),
                    '> Click "Sync crontab" to install the new Dply-managed block on the server.',
                ]),
            );
            $this->toastSuccess(__('Cron job updated. Sync crontab on the server to apply changes.'));
        } else {
            $created = ServerCronJob::query()->create(array_merge($payload, [
                'server_id' => $this->server->id,
                'enabled' => true,
            ]));
            audit_log(
                $this->server->organization,
                auth()->user(),
                'server.cron.created',
                $this->server,
                null,
                [
                    'cron_job_id' => (string) $created->id,
                    'cron_expression' => $created->cron_expression,
                    'command' => $created->command,
                    'user' => $created->user,
                    'description' => $created->description,
                    'site_id' => $created->site_id,
                    'schedule_timezone' => $created->schedule_timezone,
                    'overlap_policy' => $created->overlap_policy,
                ],
            );
            $this->emitPanelEvent(
                __('Cron job added — sync to install on server'),
                array_filter([
                    sprintf('> Added "%s" to the panel.', $created->description ?: $payload['command']),
                    sprintf('  schedule: %s · user: %s', $payload['cron_expression'], $payload['user']),
                    '> Click "Sync crontab" to install the Dply-managed block on the server.',
                ]),
            );
            $this->toastSuccess(__('Cron job added. Sync crontab on the server to install the Dply-managed block.'));
        }

        $this->cancelEdit();
        $this->dispatch('close-modal', 'add-cron-job-modal');
    }

    public function toggleCronJob(string $jobId, ServerCronSynchronizer $synchronizer): void
    {
        $this->authorize('update', $this->server);
        $job = ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($jobId);
        if ($job->system_managed) {
            $this->toastError(__('This cron line is managed automatically by Dply and can\'t be paused from here.'));

            return;
        }

        $newEnabled = ! $job->enabled;
        // Flip the panel state first so the synchronizer composes the next
        // crontab body with the new value, then push it straight to the host
        // — pause/resume are single-click operations, the operator shouldn't
        // need a separate Sync to make the line appear or disappear.
        $job->update(['enabled' => $newEnabled, 'is_synced' => false]);
        $this->server->refresh();

        audit_log(
            $this->server->organization,
            auth()->user(),
            $newEnabled ? 'server.cron.enabled' : 'server.cron.disabled',
            $this->server,
            ['enabled' => ! $newEnabled],
            [
                'cron_job_id' => (string) $job->id,
                'command' => $job->command,
                'cron_expression' => $job->cron_expression,
                'enabled' => $newEnabled,
            ],
        );

        try {
            // Pre-flight reuses the same validator the explicit Sync button uses.
            $invalid = $synchronizer->invalidExpressions($this->server->cronJobs);
            if ($invalid !== []) {
                $job->update(['enabled' => ! $newEnabled]);
                $this->toastError(__('Cannot apply: another cron job has an invalid expression. Fix it first.'));

                return;
            }

            $out = $synchronizer->sync($this->server);
            $ok = (bool) preg_match('/DPLY_CRON_EXIT:0\s*$/', $out);

            if ($ok) {
                $this->emitPanelEvent(
                    $newEnabled
                        ? __('Job resumed — added back to crontab on :host.', ['host' => $this->server->getSshConnectionString()])
                        : __('Job paused — removed from crontab on :host.', ['host' => $this->server->getSshConnectionString()]),
                    array_merge(
                        ['> '.($newEnabled ? 'Installed the line in the Dply-managed block.' : 'Omitted the line from the Dply-managed block.')],
                        $this->splitOutputForBanner($out),
                    ),
                    'completed',
                );
                $this->toastSuccess($newEnabled
                    ? __('Job resumed and crontab updated.')
                    : __('Job paused and crontab updated.'));

                return;
            }

            // Host rejected the install — reuse the rich debug surface from syncCronJobs.
            $body = (string) $synchronizer->lastBody();
            $badLine = $synchronizer->lastBadLine();
            $badContent = $synchronizer->lastBadLineContent();
            $lines = $body === '' ? [] : (preg_split("/\r?\n/", $body) ?: []);
            $width = strlen((string) count($lines));
            $numbered = [];
            foreach ($lines as $i => $line) {
                $n = $i + 1;
                $marker = $badLine !== null && $n === $badLine ? '>>' : '  ';
                $numbered[] = sprintf('%s %'.$width.'d │ %s', $marker, $n, $line);
            }
            $transcript = array_merge(
                ['> Crontab rejected by host — output:'],
                $this->splitOutputForBanner($out),
            );
            if ($badLine !== null) {
                $transcript[] = '> ';
                $transcript[] = sprintf('> Offending line %d: %s', $badLine, (string) $badContent);
            }
            if ($numbered !== []) {
                $transcript[] = '> ';
                $transcript[] = '> --- rendered crontab body (lines marked with ">>" are the rejected ones) ---';
                $transcript = array_merge($transcript, $numbered);
            }
            $this->emitPanelEvent(
                $badLine !== null
                    ? __('Crontab rejected — see line :line below.', ['line' => $badLine])
                    : __('Crontab rejected by host.'),
                $transcript,
                'failed',
            );
            $this->toastError(__('Toggle applied to panel but host rejected the new crontab — see the banner.'));
        } catch (\Throwable $e) {
            // SSH/transport failure: leave the panel-side toggle in place so the
            // operator can retry Sync once the host is reachable. is_synced
            // stays false until a successful install.
            $this->emitPanelEvent(
                __('Crontab sync failed.'),
                [
                    '> Toggled :name to :state in the panel.',
                    '> Tried to write the Dply-managed crontab block over SSH.',
                    '> ERROR: '.Str::limit(trim($e->getMessage()), 800),
                ],
                'failed',
            );
            $this->toastError($e->getMessage());
        }
    }

    public function deleteCronJob(string $jobId): void
    {
        $this->authorize('update', $this->server);
        $job = ServerCronJob::query()->where('server_id', $this->server->id)->whereKey($jobId)->firstOrFail();
        if ($job->system_managed) {
            $this->toastError(__('This cron line is managed automatically by Dply and can\'t be deleted from here.'));

            return;
        }
        $jobLabel = $job->description ?: $job->command;
        $snapshot = [
            'cron_job_id' => (string) $job->id,
            'cron_expression' => $job->cron_expression,
            'command' => $job->command,
            'user' => $job->user,
            'description' => $job->description,
            'site_id' => $job->site_id,
        ];
        $job->delete();
        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.cron.deleted',
            $this->server,
            $snapshot,
            null,
        );
        if ($this->editing_job_id === $jobId) {
            $this->cancelEdit();
        }
        $this->emitPanelEvent(
            __('Cron job removed — sync to update server'),
            [
                sprintf('> Removed "%s" from the panel.', $jobLabel),
                '> Click "Sync crontab" to remove it from the Dply-managed block on the server.',
            ],
        );
        $this->toastSuccess(__('Cron entry removed. Sync crontab again to update the server.'));
    }

    public function validateCronExpressionField(CronExpressionValidator $cronValidator): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag('new_cron_expression');
        $expr = trim($this->new_cron_expression);
        if ($cronValidator->isValid($expr)) {
            $this->toastSuccess(__('Cron expression looks valid.'));
        } else {
            $this->toastError(__('That cron expression is not valid.'));
        }
    }

    protected function resetForm(): void
    {
        $this->new_cron_expression = '* * * * *';
        $this->frequency_preset = 'every_minute';
        $this->command_preset = 'custom';
        $this->new_cron_command = '';
        $this->new_description = null;
        $this->new_site_id = $this->context_site_id;
        if ($this->context_site_id !== null) {
            $ctx = Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first();
            $this->new_cron_user = $ctx !== null
                ? $ctx->effectiveSystemUser($this->server)
                : (trim((string) $this->server->ssh_user) ?: 'root');
        } else {
            $this->new_cron_user = trim((string) $this->server->ssh_user) ?: 'root';
        }
        $this->new_schedule_timezone = config('app.timezone');
        $this->new_overlap_policy = ServerCronJob::OVERLAP_ALLOW;
        $this->new_alert_on_failure = false;
        $this->new_alert_on_pattern_match = false;
        $this->new_alert_pattern = null;
        $this->new_env_prefix = null;
        $this->new_depends_on_job_id = null;
        $this->new_maintenance_tag = null;
    }
}
