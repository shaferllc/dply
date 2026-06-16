<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

/**
 * Inline editing of a server's advisory "preferred maintenance schedule"
 * (the days/hours dply prefers to run disruptive work). Lives directly on the
 * maintenance workspace Schedule tab — it used to be buried in
 * Settings → Connection behind an "Edit in Settings" link.
 *
 * Persists to the same `server.meta` keys {@see \App\Support\Servers\MaintenanceWindow}
 * reads, so the existing advisory-window logic keeps working unchanged.
 */
trait ManagesPreferredMaintenanceSchedule
{
    /** @var array<int, string> */
    public array $schedule_days = [];

    public string $schedule_start = '';

    public string $schedule_end = '';

    public string $schedule_note = '';

    /** Load the saved schedule off the server into the form fields. */
    protected function loadPreferredMaintenanceSchedule(): void
    {
        $meta = is_array($this->server->meta) ? $this->server->meta : [];

        $days = $meta['maintenance_days'] ?? ($meta['maintenance_weekdays'] ?? []);
        $this->schedule_days = is_array($days) ? array_values(array_map('strval', $days)) : [];
        $this->schedule_start = (string) ($meta['maintenance_start'] ?? '');
        $this->schedule_end = (string) ($meta['maintenance_end'] ?? '');
        $this->schedule_note = (string) ($meta['maintenance_note'] ?? '');
    }

    public function savePreferredMaintenanceSchedule(): void
    {
        $this->authorize('update', $this->server);

        if (method_exists($this, 'currentUserIsDeployer') && $this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $allowed = array_keys(config('server_settings.maintenance_weekdays', []));

        $this->validate([
            'schedule_days' => ['array'],
            'schedule_days.*' => ['string', 'in:'.implode(',', $allowed)],
            'schedule_start' => ['nullable', 'date_format:H:i'],
            'schedule_end' => ['nullable', 'date_format:H:i'],
            'schedule_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $days = array_values(array_unique(array_intersect($this->schedule_days, $allowed)));

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['maintenance_days'] = $days;
        $meta['maintenance_start'] = $this->schedule_start !== '' ? $this->schedule_start : null;
        $meta['maintenance_end'] = $this->schedule_end !== '' ? $this->schedule_end : null;
        $meta['maintenance_note'] = trim($this->schedule_note) !== '' ? trim($this->schedule_note) : null;

        foreach (['maintenance_start', 'maintenance_end', 'maintenance_note'] as $k) {
            if (($meta[$k] ?? null) === null) {
                unset($meta[$k]);
            }
        }
        if ($days === []) {
            unset($meta['maintenance_days']);
        }
        // Drop the legacy alias so the two keys can't drift apart.
        unset($meta['maintenance_weekdays']);

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->loadPreferredMaintenanceSchedule();

        $this->toastSuccess(__('Preferred maintenance schedule saved.'));
    }
}
