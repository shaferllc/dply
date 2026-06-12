<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\WebserverSwitchPreflight;
use App\Support\Servers\ServerConsoleActionLookup;
use App\Support\Servers\WebserverWorkspaceViewData;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerWebserverSwitch
{
    /**
     * Cascade preview for a pending webserver switch — set by openSwitchWebserver()
     * when the operator clicks "Switch to <target>" on the web tab. Consumed by
     * the confirmation modal in group-web.blade.php. Null when no switch is pending.
     * Shape matches {@see WebserverSwitchPreflight::plan()}.
     *
     * @var array<string, mixed>|null
     */
    public ?array $switch_plan = null;

    /**
     * Target engine key while the switch modal is open and the preflight plan
     * is still loading. Null once {@see loadSwitchPlan()} finishes or cancel.
     */
    public ?string $switch_preflight_target = null;

    /** Opt-in: hand TLS to caddy auto-HTTPS at cutover. Greyed out for apache. */
    public bool $switch_tls_to_caddy = false;

    /**
     * Open the webserver-switch cascade modal. Computes the preflight server-side
     * (PHP-compat hard block, drift warnings, computed downtime breakdown, opt-in
     * TLS cascade) and stashes the result on the component for the modal to render.
     *
     * Refuses to open if there's already an in-flight switch ConsoleAction —
     * the live progress banner is the canonical UI while a switch is running.
     */
    public function openSwitchWebserver(string $target): void
    {
        $this->authorize('update', $this->server);

        if ($this->hasInflightWebserverSwitch()) {
            $this->toastError(__('A webserver switch is already in flight — wait for it to finish before starting another.'));

            return;
        }

        $target = strtolower(trim($target));
        if (! in_array($target, WebserverSwitchPreflight::KNOWN_WEBSERVERS, true)) {
            $this->toastError(__('Unknown webserver target: :t.', ['t' => $target]));

            return;
        }

        if (WebserverWorkspaceViewData::isComingSoonEngine($target)) {
            $label = WebserverWorkspaceViewData::webserverCatalog()[$target]['label'] ?? $target;
            $this->toastError(__(':engine switching is coming soon.', ['engine' => $label]));

            return;
        }

        $this->switch_plan = null;
        $this->switch_preflight_target = $target;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('open-modal', 'webserver-switch-modal');
    }

    /**
     * Compute the switch cascade preview after the modal opens. Kept separate
     * from {@see openSwitchWebserver()} so the confirmation shell appears
     * immediately while site/profile preflight runs.
     */
    public function loadSwitchPlan(): void
    {
        $target = $this->switch_preflight_target;
        if ($target === null || $this->switch_plan !== null) {
            return;
        }

        $this->authorize('update', $this->server);

        $plan = app(WebserverSwitchPreflight::class)->plan($this->server, $target);

        // Operator closed the modal while preflight was running.
        if ($this->switch_preflight_target !== $target) {
            return;
        }

        $this->switch_plan = $plan;
        $this->switch_tls_to_caddy = false;
        $this->switch_preflight_target = null;
    }

    /**
     * Dispatch the SwitchServerWebserverJob with the operator's opt-in selections.
     * The job seeds its own ConsoleAction row inside handle(), and the banner
     * picks it up from there. We just need to fire and forget; UI updates via
     * the banner poll.
     */
    public function confirmSwitchWebserver(): void
    {
        $this->authorize('update', $this->server);

        if ($this->switch_plan === null) {
            return;
        }
        if (($this->switch_plan['blocker'] ?? null) !== null) {
            // Modal shouldn't have allowed confirm with a blocker, but be defensive.
            $this->toastError(__('Cannot switch: :reason', ['reason' => $this->switch_plan['blocker']['label']]));

            return;
        }
        if ($this->hasInflightWebserverSwitch()) {
            $this->toastError(__('A webserver switch is already in flight.'));

            return;
        }

        $from = (string) ($this->switch_plan['from'] ?? '—');
        $target = (string) $this->switch_plan['to'];

        // Seed a queued ConsoleAction row BEFORE dispatch so the banner shows
        // immediately — without this the row only gets created when the worker
        // picks the job up, leaving operators staring at a button that "did
        // nothing." Mirrors the seedQueuedConsoleAction pattern from Sites\Show
        // for ApplySiteWebserverConfigJob.
        $this->seedQueuedWebserverSwitchAction(
            label: __('Switching webserver: :from → :to …', ['from' => $from, 'to' => $target]),
            from: $from,
            to: $target,
        );

        SwitchServerWebserverJob::dispatch(
            serverId: $this->server->id,
            target: $target,
            tlsToCaddy: $this->switch_tls_to_caddy,
            userId: auth()->id(),
        );

        $this->switch_plan = null;
        $this->switch_preflight_target = null;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('close-modal', 'webserver-switch-modal');
        $this->toastSuccess(__('Webserver switch queued. Progress shows in the banner above.'));
    }

    /**
     * Seed a queued `ConsoleAction` row for the upcoming `webserver_switch` job
     * so the banner-static partial picks it up on the next render — without
     * waiting for the worker to claim the job. Auto-dismisses prior terminal +
     * stale-running rows so the operator sees only the run they just started.
     * Mirrors {@see Show::seedQueuedConsoleAction()} but
     * scoped to a Server subject instead of a Site.
     *
     * `from`/`to` are persisted in `output['meta']` so {@see stopAndRevertWebserverSwitch()}
     * can recover them without label parsing if the operator aborts a stuck
     * switch later. The banner's {@see ConsoleAction::lines()} reader ignores
     * non-`lines` keys, so this extra metadata is safe to carry alongside.
     */
    protected function seedQueuedWebserverSwitchAction(
        ?string $label = null,
        ?string $from = null,
        ?string $to = null,
    ): ConsoleAction {
        $subjectType = $this->server->getMorphClass();
        $subjectId = $this->server->id;

        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        $output = ['v' => (int) config('console_actions.current_version', 1), 'lines' => []];
        if ($from !== null || $to !== null) {
            $output['meta'] = array_filter([
                'from' => $from,
                'to' => $to,
            ], static fn ($v) => $v !== null);
        }

        $action = ConsoleAction::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => 'webserver_switch',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => $output,
        ]);

        app(ServerConsoleActionLookup::class)->forget($this->server);

        return $action;
    }

    /**
     * Discard the pending switch — closes the modal, leaves the server untouched.
     */
    public function cancelSwitchWebserver(): void
    {
        $this->switch_plan = null;
        $this->switch_preflight_target = null;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('close-modal', 'webserver-switch-modal');
    }

    /**
     * Operator escape hatch for a stuck switch: marks the in-flight (or stale)
     * webserver_switch ConsoleAction as failed + dismissed and dispatches a
     * {@see RevertServerWebserverSwitchJob} that best-effort uninstalls the
     * partial target and brings the original webserver back on :80.
     *
     * Triggered from the "Stop & revert" button rendered alongside the banner
     * when {@see hasInflightWebserverSwitch()} is true. The from/to pair comes
     * from the seeded ConsoleAction's `output['meta']` (set by
     * {@see seedQueuedWebserverSwitchAction()}); we fall back to label parsing
     * for older rows that predate that field.
     */
    public function stopAndRevertWebserverSwitch(string $runId): void
    {
        $this->authorize('update', $this->server);

        $row = ConsoleAction::query()
            ->where('id', $runId)
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();

        if ($row === null || ! $row->isInFlight()) {
            $this->toastError(__('No in-flight webserver switch to revert.'));

            return;
        }

        $row->forceFill([
            'status' => ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => 'Aborted by operator',
            'dismissed_at' => now(),
        ])->save();

        $this->dispatchWebserverSwitchRevert($row, __('Stopping the switch and reverting :to → :from. Progress shows in the banner.'));
    }

    /**
     * After a switch fails (e.g. cutover could not start Caddy), uninstall the
     * partial target and bring the original webserver back on :80.
     */
    public function cleanupFailedWebserverSwitch(string $runId): void
    {
        $this->authorize('update', $this->server);

        $row = ConsoleAction::query()
            ->where('id', $runId)
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();

        if ($row === null || $row->isInFlight()) {
            $this->toastError(__('No failed webserver switch to clean up.'));

            return;
        }

        if ($row->status !== ConsoleAction::STATUS_FAILED) {
            $this->toastError(__('Cleanup is only available for a failed switch.'));

            return;
        }

        $row->forceFill(['dismissed_at' => now()])->save();

        $this->dispatchWebserverSwitchRevert($row, __('Cleaning up the failed switch and restoring :from on :80. Progress shows in the banner.'));
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function webserverSwitchEndpointsFromRow(ConsoleAction $row): ?array
    {
        $output = is_array($row->output) ? $row->output : [];
        $meta = is_array($output['meta'] ?? null) ? $output['meta'] : [];
        $serverWebserver = strtolower((string) ($this->server->meta['webserver'] ?? 'nginx'));
        $from = strtolower((string) ($meta['from'] ?? $serverWebserver));
        $to = strtolower((string) ($meta['to'] ?? ''));

        if ($to === '' && is_string($row->label) && preg_match('/→\s*(\S+)/u', $row->label, $m)) {
            $to = strtolower((string) $m[1]);
        }

        if ($to === '' || $to === $from) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    private function dispatchWebserverSwitchRevert(ConsoleAction $row, string $toastTemplate): void
    {
        $endpoints = $this->webserverSwitchEndpointsFromRow($row);
        if ($endpoints === null) {
            $this->toastError(__('Cannot determine which webserver to restore from this switch run.'));

            return;
        }

        $from = $endpoints['from'];
        $to = $endpoints['to'];

        $this->seedQueuedWebserverSwitchAction(
            label: __('Reverting webserver switch: :to → :from …', ['to' => $to, 'from' => $from]),
            from: $to,
            to: $from,
        );

        RevertServerWebserverSwitchJob::dispatch(
            serverId: $this->server->id,
            target: $to,
            from: $from,
            userId: auth()->id(),
        );

        $this->toastSuccess(__($toastTemplate, ['to' => $to, 'from' => $from]));
    }

    /**
     * True when there's a queued/running webserver_switch ConsoleAction for this
     * server. Used to disable the switch CTAs and short-circuit re-entry.
     */
    public function hasInflightWebserverSwitch(): bool
    {
        return app(ServerConsoleActionLookup::class)->hasInflightWebserverSwitch($this->server);
    }
}
