<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunServerCronJobNowJob;
use App\Models\ServerCronJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronRuns
{
    public ?string $cron_run_id = null;

    public string $cron_run_meta_html = '';

    public string $cron_run_output = '';

    public function runCronJobNow(string $jobId): void
    {
        $this->authorize('update', $this->server);

        $job = ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($jobId);
        if (! $job->enabled) {
            $this->toastError(__('Enable this job before running it.'));

            return;
        }

        $this->server->loadMissing('organization');
        $org = $this->server->organization;
        if ($org?->cron_maintenance_until && now()->lt($org->cron_maintenance_until)) {
            $this->toastError(__('Cron runs are paused for this organization until the maintenance window ends. Clear the organization-level maintenance window or ask an admin.'));

            return;
        }

        $runId = (string) Str::ulid();
        $this->cron_run_id = $runId;
        $this->cron_run_meta_html = '';
        $this->cron_run_output = '';

        // Seed the workspace's console banner so the operator gets immediate
        // feedback at the top of the page rather than having to scroll to a
        // separate "Live output" card. Chunks land in the same banner via
        // {@see onCronRunChunk()}.
        $this->emitPanelEvent(
            __('Running :name on :host …', [
                'name' => $job->description !== null && $job->description !== '' ? $job->description : Str::limit($job->command, 60),
                'host' => $this->server->getSshConnectionString(),
            ]),
            [],
            'running',
        );

        RunServerCronJobNowJob::dispatch($this->server->id, $job->id, $runId);

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.cron.run_now_dispatched',
            $this->server,
            null,
            [
                'cron_job_id' => (string) $job->id,
                'run_id' => $runId,
                'command' => $job->command,
                'description' => $job->description,
            ],
        );

        $rid = json_encode($runId);
        // Set active run id for Echo (may already be set from the first broadcast if the worker was fast).
        $this->js('window.__dplyCronRunActiveId='.$rid.';');

        $this->toastSuccess(__('Run queued — output streams to the banner above.'));
    }

    /**
     * Poll fallback when Echo/Reverb is unavailable (same cache payload as the queued job).
     * While status is running, do not shrink {@see $cron_run_output} below cache length so Reverb
     * chunk events are not overwritten by a slightly stale poll.
     */
    public function syncCronRunFromCache(): void
    {
        if ($this->cron_run_id === null || $this->cron_run_id === '') {
            return;
        }

        $payload = Cache::get(RunServerCronJobNowJob::cacheKey($this->cron_run_id));
        if (! is_array($payload)) {
            return;
        }

        $this->cron_run_meta_html = (string) ($payload['meta_html'] ?? '');
        $cachedOut = (string) ($payload['output'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        if (in_array($status, ['finished', 'failed'], true)) {
            $this->cron_run_output = $cachedOut;
        } elseif (strlen($cachedOut) >= strlen($this->cron_run_output)) {
            $this->cron_run_output = $cachedOut;
        }

        // Keep the console banner in lockstep with the polled output.
        $this->panel_event_lines = $this->cron_run_output === ''
            ? []
            : (preg_split("/\r?\n/", rtrim($this->cron_run_output)) ?: []);

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        $success = $status === 'finished';
        $this->emitPanelEvent(
            $success
                ? (string) ($payload['flash_success'] ?? __('Run finished.'))
                : (string) ($payload['error'] ?? __('Run failed.')),
            $this->panel_event_lines,
            $success ? 'completed' : 'failed',
        );

        $this->cron_run_id = null;
        if ($success) {
            $this->toastSuccess((string) ($payload['flash_success'] ?? __('Finished.')));
        } else {
            $this->toastError((string) ($payload['error'] ?? __('Run failed.')));
        }
    }

    #[On('cron-run-meta')]
    public function onCronRunMeta(string $runId, string $metaHtml): void
    {
        if ($runId === '') {
            return;
        }
        if ($this->cron_run_id !== null && $this->cron_run_id !== '' && $runId !== $this->cron_run_id) {
            return;
        }

        $this->cron_run_meta_html = $metaHtml;
        $this->cron_run_output = '';
    }

    #[On('cron-run-chunk')]
    public function onCronRunChunk(string $runId, string $chunk): void
    {
        if ($runId === '' || $chunk === '') {
            return;
        }
        if ($this->cron_run_id !== $runId) {
            return;
        }

        $this->cron_run_output .= $chunk;
        $this->panel_event_lines = preg_split("/\r?\n/", rtrim($this->cron_run_output)) ?: [];
    }

    #[On('cron-run-finished')]
    public function onCronRunFinished(mixed ...$payload): void
    {
        $runId = '';
        $success = false;
        $flashSuccess = null;
        $error = null;

        $first = $payload[0] ?? null;
        if (is_array($first)) {
            $runId = (string) ($first['runId'] ?? $first['run_id'] ?? '');
            $success = (bool) ($first['success'] ?? false);
            $flashSuccess = $first['flashSuccess'] ?? $first['flash_success'] ?? null;
            $error = isset($first['error']) ? (is_string($first['error']) ? $first['error'] : null) : null;
        } elseif (count($payload) >= 2) {
            $runId = (string) $payload[0];
            $success = (bool) $payload[1];
            $flashSuccess = isset($payload[2]) && is_string($payload[2]) ? $payload[2] : null;
            $error = isset($payload[3]) && is_string($payload[3]) ? $payload[3] : null;
        }

        if ($runId === '' || $this->cron_run_id !== $runId) {
            return;
        }

        $cached = Cache::get(RunServerCronJobNowJob::cacheKey($runId));
        if (is_array($cached)) {
            $this->cron_run_meta_html = (string) ($cached['meta_html'] ?? $this->cron_run_meta_html);
            $this->cron_run_output = (string) ($cached['output'] ?? $this->cron_run_output);
        }

        $finalLines = $this->cron_run_output === ''
            ? []
            : (preg_split("/\r?\n/", rtrim($this->cron_run_output)) ?: []);
        $this->emitPanelEvent(
            $success
                ? (is_string($flashSuccess) && $flashSuccess !== '' ? $flashSuccess : __('Run finished.'))
                : (is_string($error) && $error !== '' ? $error : __('Run failed.')),
            $finalLines,
            $success ? 'completed' : 'failed',
        );

        $this->cron_run_id = null;
        if ($success) {
            $this->toastSuccess(is_string($flashSuccess) && $flashSuccess !== '' ? $flashSuccess : __('Finished.'));
        } else {
            $this->toastError(is_string($error) && $error !== '' ? $error : __('Run failed.'));
        }
    }

    /**
     * Whether Echo may subscribe for live cron run chunks (Reverb + channel policy).
     */
    public function cronRunEchoSubscribable(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->can('view', $this->server)) {
            return false;
        }

        $this->server->loadMissing('organization');

        if ($this->server->organization_id && $this->server->organization?->userIsDeployer($user)) {
            return false;
        }

        if (config('broadcasting.default') === 'null') {
            return false;
        }

        if (! config('broadcasting.echo_client_enabled', true)) {
            return false;
        }

        return filled(config('broadcasting.connections.reverb.key'));
    }

    /**
     * Split a buffered SSH command output into transcript lines for the panel banner —
     * mirrors the helper on the firewall workspace, kept local since the format is the same.
     *
     * @return list<string>
     */
    protected function splitOutputForBanner(string $blob, int $maxLines = 200): array
    {
        $lines = array_values(array_filter(
            array_map('rtrim', preg_split("/\r?\n/", trim($blob)) ?: []),
            static fn (string $l): bool => $l !== '',
        ));

        return count($lines) > $maxLines
            ? array_merge(array_slice($lines, 0, $maxLines), [sprintf('… (%d more lines truncated)', count($lines) - $maxLines)])
            : $lines;
    }
}
