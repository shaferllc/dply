<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\FunctionInvocation;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Serverless\FunctionInvoker;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Logs workspace for a serverless (DigitalOcean Functions) site.
 *
 * DO never persists a queryable activation list, so every tab here is fed
 * by the `function_invocations` table — rows dply records itself:
 *
 *  - Activations — dply-initiated invocations (background ticks + the test
 *    button below), captured inline from the authenticated blocking API.
 *  - Visits — organic HTTP traffic, POSTed in by the deployed handler.
 *  - Runtime output — the log lines from every row, flattened into one
 *    stream. For a FaaS Laravel app this is the application log.
 *  - Deploy logs — the SiteDeployment history (unchanged).
 */
class LogsPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    /**
     * Active tab: activations | visits | runtime | deploy. Aliased to
     * `?logs=` so it cannot collide with the `?tab=` param the routing
     * section of the surrounding settings page already owns.
     */
    #[Url(as: 'logs')]
    public string $tab = 'activations';

    /** Inline "Send test request" form on the Activations tab. */
    public bool $testFormOpen = false;

    public string $testMethod = 'GET';

    public string $testPath = '/';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['activations', 'visits', 'runtime', 'deploy'], true)
            ? $tab
            : 'activations';
    }

    public function toggleTestForm(): void
    {
        $this->testFormOpen = ! $this->testFormOpen;
    }

    /** Re-renders, which re-queries every log source. */
    public function refreshLogs(): void {}

    /**
     * Invoke the function once from the UI via the authenticated blocking
     * API, recording the activation as a `source=test` row.
     */
    public function sendTestRequest(FunctionInvoker $invoker): void
    {
        $site = Site::with('server')->findOrFail($this->siteId);
        $this->authorize('update', $site);

        $method = strtoupper(trim($this->testMethod)) ?: 'GET';
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            $method = 'GET';
        }

        $result = $invoker->invoke($site, FunctionInvocation::SOURCE_TEST, null, [
            '__ow_method' => $method,
            '__ow_path' => ltrim(trim($this->testPath), '/'),
            '__ow_headers' => ['accept' => 'text/html'],
            '__ow_query' => '',
        ]);

        $this->testFormOpen = false;
        $this->tab = 'activations';

        if (! $result['ok']) {
            $this->toastError(__('Test request failed: :error', ['error' => $result['error'] ?? __('unknown error')]));

            return;
        }

        $invocation = $result['invocation'];
        $this->toastSuccess($invocation !== null && $invocation->success
            ? __('Test request succeeded — HTTP :status, :ms ms.', [
                'status' => $invocation->status_code ?? '—',
                'ms' => $invocation->duration_ms,
            ])
            : __('Test request ran but the function reported an error — see the row below.'));
    }

    public function render(): View
    {
        $site = Site::with('server')->findOrFail($this->siteId);
        $this->authorize('view', $site);

        $activations = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->operational()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $visits = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->organic()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Runtime output — log lines from every recent invocation, regardless
        // of source, flattened oldest-first into one chronological stream.
        $runtimeLines = [];
        $runtimeRows = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->sortBy('created_at');
        foreach ($runtimeRows as $row) {
            foreach ($row->logLines() as $line) {
                $runtimeLines[] = $line;
            }
        }

        $deployments = SiteDeployment::query()
            ->where('site_id', $this->siteId)
            ->orderByDesc('started_at')
            ->limit(15)
            ->get();

        $errors = $activations->filter(fn (FunctionInvocation $i): bool => ! $i->success)->count();
        $total = $activations->count();

        return view('livewire.serverless.logs-panel', [
            'site' => $site,
            'activations' => $activations,
            'visits' => $visits,
            'runtimeLines' => $runtimeLines,
            'deployments' => $deployments,
            'metrics' => [
                'total' => $total,
                'error_rate' => $total > 0 ? (int) round($errors / $total * 100) : 0,
                'avg_duration' => $total > 0 ? (int) round($activations->avg('duration_ms')) : 0,
                'cold_starts' => $activations->filter(fn (FunctionInvocation $i): bool => $i->cold)->count(),
            ],
        ]);
    }
}
