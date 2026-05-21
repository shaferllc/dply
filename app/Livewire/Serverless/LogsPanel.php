<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Models\Site;
use App\Services\Serverless\ActivationLog;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Shows recent OpenWhisk activation records for a serverless function — the
 * runtime logs + results behind the `serverless:logs` CLI, in the workspace.
 */
class LogsPanel extends Component
{
    public string $siteId = '';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    /** Re-renders, which re-fetches the activations. */
    public function refreshLogs(): void {}

    public function render(ActivationLog $activationLog): View
    {
        $result = $activationLog->recent(Site::findOrFail($this->siteId));
        $activations = $result['activations'];
        $total = count($activations);

        return view('livewire.serverless.logs-panel', [
            'ok' => $result['ok'],
            'error' => $result['error'],
            'activations' => $activations,
            'metrics' => [
                'total' => $total,
                'errors' => $errors = count(array_filter($activations, fn ($a) => ! $a['success'])),
                'error_rate' => $total > 0 ? (int) round($errors / $total * 100) : 0,
                'avg_duration' => $total > 0 ? (int) round(array_sum(array_column($activations, 'duration')) / $total) : 0,
                'cold_starts' => count(array_filter($activations, fn ($a) => $a['cold'])),
            ],
        ]);
    }
}
