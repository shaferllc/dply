<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Serverless\Contracts\ServerlessFeature;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Services\AsyncFunctionInvoker;
use App\Modules\Serverless\Services\FunctionInvoker;
use App\Modules\Serverless\Services\ServerlessFeatureMatrix;
use App\Modules\Serverless\Services\ServerlessLogDrainProvisioner;
use App\Services\Logging\LoggingChannelCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
 *
 * Everything above is bounded and unsearchable by construction: the tabs read
 * fixed slices of `function_invocations`, and the pruner drops organic rows
 * after 7 days. The drain toggle here is the way out of that — pointed at
 * dply's drain, the same lines land in `app_logs`, which is searchable,
 * level-filterable, and kept for 30 days.
 */
class LogsPanel extends Component
{
    use DispatchesToastNotifications;
    use WithPagination;

    /** Activations per page. The tab is a scan-for-the-bad-one list, not a feed. */
    private const ACTIVATIONS_PER_PAGE = 25;

    /** How far back the health counters look, regardless of the page shown. */
    private const METRICS_WINDOW = 50;

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

    /**
     * Start the test invocation instead of waiting for it. Required for a
     * function whose timeout exceeds what a blocking call can hold open.
     */
    public bool $testAsync = false;

    /**
     * Runtime-tab filters. The level is parsed off each line rather than read
     * from a column — these are opaque strings, see {@see self::levelOf()}.
     */
    public string $runtimeLevel = '';

    public string $runtimeSearch = '';

    /** 15m | 1h | 24h | 7d | all — window over the invocation's created_at. */
    public string $runtimeRange = 'all';

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
     * Start shipping this function's application log to dply's drain.
     *
     * Takes effect on the next deploy: the variables go into the managed
     * environment, which is bundled into the artifact at build time — a
     * function has no way to be reconfigured in place.
     */
    public function enableLogDrain(ServerlessLogDrainProvisioner $drains): void
    {
        $site = Site::findOrFail($this->siteId);
        $this->authorize('update', $site);

        try {
            $drains->enable($site);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Log drain attached — it starts shipping on the next deploy.'));
    }

    public function disableLogDrain(ServerlessLogDrainProvisioner $drains): void
    {
        $site = Site::findOrFail($this->siteId);
        $this->authorize('update', $site);

        $drains->disable($site);

        $this->toastSuccess(__('Log drain removed — logging returns to stderr on the next deploy.'));
    }

    /**
     * Invoke the function once from the UI, recording the activation as a
     * `source=test` row.
     *
     * Blocking by default — the activation comes back inline and the row is
     * complete when this returns. Async hands off to the poller instead,
     * which is the only way to drive a function whose timeout is longer than
     * a synchronous call can be held open.
     */
    public function sendTestRequest(FunctionInvoker $invoker, AsyncFunctionInvoker $asyncInvoker): void
    {
        $site = Site::with('server')->findOrFail($this->siteId);
        $this->authorize('update', $site);

        $method = strtoupper(trim($this->testMethod)) ?: 'GET';
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            $method = 'GET';
        }

        $event = [
            '__ow_method' => $method,
            '__ow_path' => ltrim(trim($this->testPath), '/'),
            '__ow_headers' => ['accept' => 'text/html'],
            '__ow_query' => '',
        ];

        $result = $this->testAsync
            ? $asyncInvoker->invoke($site, FunctionInvocation::SOURCE_TEST, null, $event)
            : $invoker->invoke($site, FunctionInvocation::SOURCE_TEST, null, $event);

        $this->testFormOpen = false;
        $this->tab = 'activations';

        if (! $result['ok']) {
            $this->toastError(__('Test request failed: :error', ['error' => $result['error'] ?? __('unknown error')]));

            return;
        }

        if ($this->testAsync) {
            $this->toastSuccess(__('Invocation started — the result appears here when it finishes.'));

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

    public function resetRuntimeFilters(): void
    {
        $this->runtimeLevel = '';
        $this->runtimeSearch = '';
        $this->runtimeRange = 'all';
    }

    /** Start of the runtime tab's window; null when the range is 'all'. */
    private function runtimeSince(): ?Carbon
    {
        return match ($this->runtimeRange) {
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => null,
        };
    }

    /**
     * The Monolog level a runtime line carries, or null when the line is not a
     * record header. Matches the default LineFormatter header that the injected
     * handler writes (`[datetime] channel.LEVEL: message`), optionally behind
     * the `<timestamp> stdout:` prefix DigitalOcean prepends to activation logs.
     */
    private static function levelOf(string $line): ?string
    {
        return preg_match('/^(?:\S+\s+std(?:out|err):\s*)?\[[^\]]+\]\s+\S+\.([A-Za-z]+):/', $line, $m) === 1
            ? strtolower($m[1])
            : null;
    }

    public function render(): View
    {
        $site = Site::with('server')->findOrFail($this->siteId);
        $this->authorize('view', $site);

        // Paginated: a busy function racks up hundreds of ticks, and the old
        // limit(50) silently hid everything older with no way to reach it.
        $activations = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->operational()
            ->orderByDesc('created_at')
            ->paginate(self::ACTIVATIONS_PER_PAGE, ['*'], 'activationsPage');

        // Counters describe the recent window, not the page being read — paging
        // back through history must not change what "24h health" says.
        $recentActivations = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->operational()
            ->orderByDesc('created_at')
            ->limit(self::METRICS_WINDOW)
            ->get();

        $visits = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->organic()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Runtime output — log lines from every recent invocation, regardless
        // of source, flattened oldest-first into one chronological stream.
        // The time range bounds the invocations we read; level and search then
        // filter the lines those invocations carry.
        $runtimeLines = [];
        $runtimeTotal = 0;
        $runtimeRows = FunctionInvocation::query()
            ->where('site_id', $this->siteId)
            ->when($this->runtimeSince(), fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->sortBy('created_at');
        $needle = trim($this->runtimeSearch);
        foreach ($runtimeRows as $row) {
            // Continuation lines (a stack trace, a bare stdout write) belong to
            // the record above them, so the level carries down until the next
            // header. It resets per invocation — a new activation starts fresh.
            $level = null;
            foreach ($row->logLines() as $line) {
                $level = self::levelOf($line) ?? $level;
                $runtimeTotal++;

                if ($this->runtimeLevel !== '' && $level !== $this->runtimeLevel) {
                    continue;
                }
                // ponytail: substring match per line, so a hit inside a stack
                // trace shows that line without its header. Group by record if
                // that reads badly in practice.
                if ($needle !== '' && stripos($line, $needle) === false) {
                    continue;
                }

                $runtimeLines[] = $line;
            }
        }

        $deployments = SiteDeployment::query()
            ->where('site_id', $this->siteId)
            ->orderByDesc('started_at')
            ->limit(15)
            ->get();

        // Metrics describe finished invocations only — an in-flight async row
        // has no duration and no outcome to average in.
        $settled = $recentActivations->reject(fn (FunctionInvocation $i): bool => $i->isPending());
        $errors = $settled->filter(fn (FunctionInvocation $i): bool => ! $i->success)->count();
        $total = $settled->count();

        $drains = app(ServerlessLogDrainProvisioner::class);

        return view('livewire.serverless.logs-panel', [
            'site' => $site,
            'drainAvailable' => $drains->isAvailable(),
            'drainEnabled' => $drains->isEnabled($site),
            'supportsAsync' => app(ServerlessFeatureMatrix::class)
                ->siteSupports($site, ServerlessFeature::AsyncInvocation),
            // In-flight async invocations keep the page polling until they
            // land; without this the row would sit at "running" until the
            // operator refreshed by hand.
            'pendingActivations' => $recentActivations
                ->filter(fn (FunctionInvocation $i): bool => $i->state === FunctionInvocation::STATE_PENDING)
                ->count(),
            'activations' => $activations,
            'visits' => $visits,
            'runtimeLines' => $runtimeLines,
            'runtimeTotal' => $runtimeTotal,
            'runtimeLevels' => LoggingChannelCatalog::LEVELS,
            'runtimeFiltered' => $this->runtimeLevel !== '' || trim($this->runtimeSearch) !== '' || $this->runtimeRange !== 'all',
            'deployments' => $deployments,
            'metrics' => [
                'total' => $total,
                'error_rate' => $total > 0 ? (int) round($errors / $total * 100) : 0,
                'avg_duration' => $total > 0 ? (int) round($settled->avg('duration_ms')) : 0,
                'cold_starts' => $settled->filter(fn (FunctionInvocation $i): bool => $i->cold)->count(),
            ],
        ]);
    }
}
