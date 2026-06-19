<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ServerLogAlertRule;
use App\Modules\Logs\Services\ServerLogEntitlements;
use Illuminate\Support\Collection;

/**
 * Drives the "Alerts" section of the Logs workspace — the dply Logs paid-tier
 * alerting feature: CRUD over per-server {@see ServerLogAlertRule}s that fire a
 * notification when shipped logs cross a threshold over a rolling window. Every
 * mutation is gated on the org's `alerting_enabled` entitlement; the rules
 * themselves are evaluated out-of-band by the scheduled evaluator, never here.
 *
 * Requires the host component to also use {@see DispatchesToastNotifications}
 * and {@see InteractsWithServerWorkspace} (provides $server + authorize()).
 */
trait ManagesServerLogAlerts
{
    public bool $logAlertFormOpen = false;

    public ?string $logAlertEditingId = null;

    public string $logAlertName = '';

    public string $logAlertType = ServerLogAlertRule::TYPE_RATE;

    public string $logAlertLevel = '';

    public string $logAlertSource = '';

    public string $logAlertSearch = '';

    public int $logAlertThreshold = 10;

    public int $logAlertWindowMinutes = 5;

    public int $logAlertCooldownMinutes = 60;

    /** True when the org's plan includes dply Logs alerting (gates the whole tab). */
    public function getLogAlertingAvailableProperty(): bool
    {
        $organization = $this->server->organization;
        if ($organization === null) {
            return false;
        }

        return app(ServerLogEntitlements::class)->forOrganization($organization)->alertingEnabled;
    }

    /**
     * This server's alert rules, newest first, for the tab list.
     *
     * @return Collection<int, ServerLogAlertRule>
     */
    public function loadLogAlertRules(): Collection
    {
        return ServerLogAlertRule::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function openLogAlertForm(): void
    {
        if (! $this->guardAlerting()) {
            return;
        }

        $this->resetLogAlertForm();
        $this->logAlertFormOpen = true;
    }

    public function cancelLogAlertForm(): void
    {
        $this->resetLogAlertForm();
        $this->logAlertFormOpen = false;
    }

    public function editLogAlertRule(string $ruleId): void
    {
        if (! $this->guardAlerting()) {
            return;
        }

        $rule = $this->findLogAlertRule($ruleId);
        if ($rule === null) {
            return;
        }

        $this->logAlertEditingId = $rule->id;
        $this->logAlertName = $rule->name;
        $this->logAlertType = $rule->type;
        $this->logAlertLevel = (string) ($rule->level ?? '');
        $this->logAlertSource = (string) ($rule->source ?? '');
        $this->logAlertSearch = (string) ($rule->search ?? '');
        $this->logAlertThreshold = $rule->threshold;
        $this->logAlertWindowMinutes = $rule->window_minutes;
        $this->logAlertCooldownMinutes = $rule->cooldown_minutes;
        $this->logAlertFormOpen = true;
    }

    public function saveLogAlertRule(): void
    {
        if (! $this->guardAlerting()) {
            return;
        }

        $sourceKeys = array_keys((array) config('server_logs.sources', []));

        $validated = $this->validate([
            'logAlertName' => ['required', 'string', 'max:120'],
            'logAlertType' => ['required', 'in:'.ServerLogAlertRule::TYPE_RATE.','.ServerLogAlertRule::TYPE_PATTERN],
            'logAlertLevel' => ['nullable', 'string', 'max:32'],
            'logAlertSource' => ['nullable', 'string', 'in:'.implode(',', $sourceKeys)],
            'logAlertSearch' => ['nullable', 'string', 'max:200'],
            'logAlertThreshold' => ['required', 'integer', 'min:1', 'max:1000000'],
            'logAlertWindowMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'logAlertCooldownMinutes' => ['required', 'integer', 'min:0', 'max:10080'],
        ]);

        // A pattern rule is meaningless without text to match on.
        if ($validated['logAlertType'] === ServerLogAlertRule::TYPE_PATTERN && trim($validated['logAlertSearch'] ?? '') === '') {
            $this->addError('logAlertSearch', __('A pattern alert needs text to match.'));

            return;
        }

        // A pattern alert fires on the first matching line, so its threshold is
        // always 1 (the form disables the threshold field for this type).
        $isPattern = $validated['logAlertType'] === ServerLogAlertRule::TYPE_PATTERN;

        $attributes = [
            'name' => $validated['logAlertName'],
            'type' => $validated['logAlertType'],
            'level' => trim($validated['logAlertLevel'] ?? '') !== '' ? trim($validated['logAlertLevel']) : null,
            'source' => trim($validated['logAlertSource'] ?? '') !== '' ? trim($validated['logAlertSource']) : null,
            'search' => trim($validated['logAlertSearch'] ?? '') !== '' ? trim($validated['logAlertSearch']) : null,
            'threshold' => $isPattern ? 1 : $validated['logAlertThreshold'],
            'window_minutes' => $validated['logAlertWindowMinutes'],
            'cooldown_minutes' => $validated['logAlertCooldownMinutes'],
        ];

        if ($this->logAlertEditingId !== null) {
            $rule = $this->findLogAlertRule($this->logAlertEditingId);
            if ($rule === null) {
                return;
            }
            $rule->update($attributes);
            $this->toastSuccess(__('Alert rule updated.'));
        } else {
            ServerLogAlertRule::query()->create($attributes + [
                'server_id' => $this->server->id,
                'organization_id' => $this->server->organization_id,
                'enabled' => true,
            ]);
            $this->toastSuccess(__('Alert rule created.'));
        }

        $this->resetLogAlertForm();
        $this->logAlertFormOpen = false;
    }

    public function toggleLogAlertRule(string $ruleId): void
    {
        if (! $this->guardAlerting()) {
            return;
        }

        $rule = $this->findLogAlertRule($ruleId);
        if ($rule === null) {
            return;
        }

        $rule->update(['enabled' => ! $rule->enabled]);
        $this->toastSuccess($rule->enabled ? __('Alert enabled.') : __('Alert paused.'));
    }

    public function deleteLogAlertRule(string $ruleId): void
    {
        if (! $this->guardAlerting()) {
            return;
        }

        $rule = $this->findLogAlertRule($ruleId);
        if ($rule === null) {
            return;
        }

        $rule->delete();
        $this->toastSuccess(__('Alert rule deleted.'));
    }

    protected function resetLogAlertForm(): void
    {
        $this->logAlertEditingId = null;
        $this->logAlertName = '';
        $this->logAlertType = ServerLogAlertRule::TYPE_RATE;
        $this->logAlertLevel = '';
        $this->logAlertSource = '';
        $this->logAlertSearch = '';
        $this->logAlertThreshold = 10;
        $this->logAlertWindowMinutes = 5;
        $this->logAlertCooldownMinutes = 60;
        $this->resetErrorBag([
            'logAlertName', 'logAlertType', 'logAlertLevel', 'logAlertSource',
            'logAlertSearch', 'logAlertThreshold', 'logAlertWindowMinutes', 'logAlertCooldownMinutes',
        ]);
    }

    /** Scope rule lookups to this server so a stray id can't reach another server's rule. */
    protected function findLogAlertRule(string $ruleId): ?ServerLogAlertRule
    {
        return ServerLogAlertRule::query()
            ->where('server_id', $this->server->id)
            ->find($ruleId);
    }

    /** Authorize + entitlement gate shared by every mutation. */
    protected function guardAlerting(): bool
    {
        $this->authorize('update', $this->server);

        if (! $this->logAlertingAvailable) {
            $this->toastError(__('Log alerting is available on the Pro and Business plans.'));

            return false;
        }

        return true;
    }
}
