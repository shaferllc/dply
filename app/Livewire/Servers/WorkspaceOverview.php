<?php

namespace App\Livewire\Servers;

use App\Jobs\CheckServerHealthJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceOverview extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $health_check_url = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->health_check_url = (string) ($server->meta['health_check_url'] ?? '');
    }

    public function checkHealth(): void
    {
        $this->authorize('view', $this->server);
        if ($this->server->status === Server::STATUS_READY && ! empty($this->server->ip_address)) {
            CheckServerHealthJob::dispatch($this->server);
        }
        $this->flash_success = 'Health check has been queued. Status will update shortly.';
    }

    public function saveHealthCheckUrl(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['health_check_url' => 'nullable|string|url|max:500']);
        $meta = $this->server->meta ?? [];
        $meta['health_check_url'] = trim($this->health_check_url) ?: null;
        if ($meta['health_check_url'] === null) {
            unset($meta['health_check_url']);
        }
        $this->server->update(['meta' => $meta]);
        $this->flash_success = 'Health check URL updated.';
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! RunSetupScriptJob::shouldDispatch($server)) {
            $this->flash_error = 'This server is not ready for a setup re-run yet.';

            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['provision_task_id']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        WaitForServerSshReadyJob::dispatch($server->fresh());

        $this->redirectRoute('servers.journey', $server, navigate: true);
    }

    public function render(): View
    {
        $this->server->refresh();

        $siteSummaries = $this->server->sites()
            ->with(['domains'])
            ->orderBy('name')
            ->limit(4)
            ->get()
            ->map(function (Site $site): array {
                $primaryDomain = $site->domains->firstWhere('is_primary', true) ?? $site->domains->first();

                return [
                    'name' => $site->name,
                    'status' => $site->status,
                    'primary_domain' => $primaryDomain?->hostname,
                    'route' => route('sites.show', ['server' => $this->server, 'site' => $site]),
                ];
            });

        $siteIds = $this->server->sites()->pluck('id');
        $latestDeployment = $siteIds->isEmpty()
            ? null
            : SiteDeployment::query()
                ->with('site')
                ->whereIn('site_id', $siteIds)
                ->latest('created_at')
                ->first();

        $monitorLastSampleAt = isset(($this->server->meta ?? [])['monitoring_last_sample_at'])
            ? Carbon::parse($this->server->meta['monitoring_last_sample_at'])->timezone(config('app.timezone'))
            : null;

        $opsSummary = [
            'firewall_rules_enabled' => $this->server->firewallRules()->where('enabled', true)->count(),
            'cron_jobs' => $this->server->cronJobs()->count(),
            'daemons' => $this->server->supervisorPrograms()->count(),
            'ssh_keys' => $this->server->authorizedKeys()->count(),
        ];

        $healthSummary = [
            'status' => $this->server->health_status,
            'last_checked_at' => $this->server->last_health_check_at,
            'monitor_last_sample_at' => $monitorLastSampleAt,
        ];

        $insightFindings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('detected_at')
            ->limit(3)
            ->get();

        $openInsightQuery = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN);

        $insightSummary = [
            'open_count' => (clone $openInsightQuery)->count(),
            'critical_count' => (clone $openInsightQuery)->where('severity', InsightFinding::SEVERITY_CRITICAL)->count(),
            'warning_count' => (clone $openInsightQuery)->where('severity', InsightFinding::SEVERITY_WARNING)->count(),
            'info_count' => (clone $openInsightQuery)->where('severity', InsightFinding::SEVERITY_INFO)->count(),
            'latest_detected_at' => (clone $openInsightQuery)->max('detected_at'),
        ];

        return view('livewire.servers.workspace-overview', [
            'siteSummaries' => $siteSummaries,
            'siteCount' => $this->server->sites()->count(),
            'latestDeployment' => $latestDeployment,
            'opsSummary' => $opsSummary,
            'healthSummary' => $healthSummary,
            'insightFindings' => $insightFindings,
            'insightSummary' => $insightSummary,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
