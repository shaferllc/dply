<?php

namespace App\Livewire\Servers;

use App\Modules\Insights\Jobs\ApplyInsightFixJob;
use App\Modules\Insights\Jobs\RevertInsightFixJob;
use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesInsightsBanner;
use App\Livewire\Servers\Concerns\ManagesInsightsFindings;
use App\Livewire\Servers\Concerns\ManagesInsightsFixes;
use App\Livewire\Servers\Concerns\ManagesInsightsNotifications;
use App\Livewire\Servers\Concerns\ManagesInsightsSettings;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\Insights\Services\InsightSettingsRepository;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceInsights extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.insights';

    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesInsightsBanner;
    use ManagesInsightsFindings;
    use ManagesInsightsFixes;
    use ManagesInsightsNotifications;
    use ManagesInsightsSettings;

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public string $tab = 'overview';

    /** @var array<string, bool> */
    public array $enabled_map = [];

    /** @var array<string, mixed> */
    public array $parameters = [];

    public bool $running = false;

    public bool $showApplyFixModal = false;

    public ?int $applyFixFindingId = null;

    /**
     * The finding currently being inspected in the detail modal.
     * Null when the modal is closed.
     */
    public ?int $detailFindingId = null;

    public function mount(Server $server): void
    {
        if (! Feature::active('workspace.insights')) {
            if (workspace_insights_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);
        $this->loadSettings();
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }


    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to the insights alert.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }


    // ─── Workspace banner state ──────────────────────────────────────────────────────────
    //
    // The console banner mirrors the firewall / SSH-keys workspace pattern. Three banner
    // sources share one slot — `insights_run`, `insights_fix`, `insights_revert` — and a
    // single mutex (`isInsightsBusy`) blocks concurrent dispatches. State is seeded on
    // dispatch (status=queued) and updated by the job through completion. Output streams
    // through the application cache keyed by run_id, with a TTL ~5 minutes.

    public const STALE_THRESHOLD_SECONDS = 300;


    public function render(): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-insights-preview');
        }

        $org = $this->server->organization;
        $orgHasPro = $org?->onAnyPaidPlan() ?? false;

        $catalog = [];
        $enabledChecks = 0;
        $implementedChecks = 0;
        $installedServiceTags = ServerInstalledServices::tagsFor($this->server);
        $hasUnknownStack = array_key_exists('unknown', $installedServiceTags);
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
                continue;
            }

            // Skip checks whose backing service isn't installed (e.g. InnoDB on a
            // database-less server). Fail open if the stack summary is unavailable so
            // freshly-imported servers still surface everything.
            $requires = is_array($def['requires'] ?? null) ? $def['requires'] : [];
            if (! $hasUnknownStack && $requires !== []) {
                $present = false;
                foreach ($requires as $tag) {
                    if (array_key_exists($tag, $installedServiceTags)) {
                        $present = true;
                        break;
                    }
                }
                if (! $present) {
                    continue;
                }
            }

            $catalog[$key] = $def;

            $enabled = (bool) ($this->enabled_map[$key] ?? false);
            if ($enabled) {
                $enabledChecks++;
            }

            $runnerClass = $def['runner'] ?? null;
            if ($enabled && is_string($runnerClass) && class_exists($runnerClass)) {
                $implementedChecks++;
            }
        }

        $severityOrder = "CASE severity WHEN 'critical' THEN 30 WHEN 'warning' THEN 20 WHEN 'info' THEN 10 ELSE 0 END";
        $findings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->orderByRaw($severityOrder.' DESC')
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get();

        // Ignored suggestions still within their cooldown window — surfaced separately so
        // the user has a way to restore one if they change their mind. Server-scoped only.
        $ignoredSuggestions = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_IGNORED)
            ->where('kind', InsightFinding::KIND_SUGGESTION)
            ->orderByDesc('ignored_at')
            ->limit(20)
            ->get();

        // Recently applied config-mutating fixes that still have an on-disk backup we can revert from.
        // Pulled from resolved findings with meta.backup_path set, last 30 days, server-scoped.
        $recentlyAppliedFindings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_RESOLVED)
            ->where('resolved_at', '>=', now()->subDays(30))
            ->whereRaw("(meta->>'backup_path') is not null and (meta->>'backup_path') <> ''")
            ->orderByDesc('resolved_at')
            ->limit(20)
            ->get();

        // Split by kind: problems can page + populate the critical banner; suggestions are
        // tuning recommendations rendered in their own section, never in the banner.
        // Treat NULL kind as PROBLEM — earlier records pre-date the kind column and
        // would otherwise vanish from the page even though the badge counts them.
        $problemFindings = $findings
            ->filter(fn (InsightFinding $f): bool => $f->kind !== InsightFinding::KIND_SUGGESTION)
            ->values();
        $suggestionFindings = $findings->where('kind', InsightFinding::KIND_SUGGESTION)->values();

        // Dismissed = acknowledged problems. Pulled out of the Overview list so
        // a noisy week of acks doesn't bury the actually-active findings. Lives
        // on its own tab; rows can be restored from there.
        $dismissedFindings = $problemFindings
            ->filter(fn (InsightFinding $f): bool => $f->acknowledged_at !== null)
            ->values();
        $activeFindings = $problemFindings
            ->filter(fn (InsightFinding $f): bool => $f->acknowledged_at === null)
            ->values();

        // Count of unacknowledged critical *problems* — drives the condensed
        // critical banner (summary + Dismiss all). Suggestions are already
        // excluded since this counts $activeFindings (problems only).
        $criticalCount = $activeFindings
            ->where('severity', InsightFinding::SEVERITY_CRITICAL)
            ->count();

        return view('livewire.servers.workspace-insights', [
            'orgHasPro' => $orgHasPro,
            'findings' => $activeFindings,
            'dismissedFindings' => $dismissedFindings,
            'suggestionFindings' => $suggestionFindings,
            'ignoredSuggestions' => $ignoredSuggestions,
            'criticalCount' => $criticalCount,
            'recentlyAppliedFindings' => $recentlyAppliedFindings,
            'insightsCatalog' => $catalog,
            'enabledChecks' => $enabledChecks,
            'implementedChecks' => $implementedChecks,
            'selectedFixFinding' => $this->applyFixFindingId === null
                ? null
                : $findings->firstWhere('id', $this->applyFixFindingId),
            'notifChannels' => $this->tab === 'notifications' ? $this->assignableInsightsNotificationChannels() : collect(),
            'notifSubscriptions' => $this->tab === 'notifications' ? $this->insightsNotificationSubscriptions() : collect(),
            'notifEventLabels' => $this->tab === 'notifications' ? $this->insightsEventLabels() : [],
        ]);
    }
}
