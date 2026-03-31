<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\DeleteServerAction;
use App\Livewire\Concerns\ManagesServerRemovalForm;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Services\Insights\OrganizationInsightsMetricsService;
use App\Services\Servers\ServerRemovalAdvisor;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use ManagesServerRemovalForm;

    public string $search = '';

    public string $sort = 'created_at';

    /** @var string all|pending|provisioning|ready|error|disconnected */
    public string $statusFilter = '';

    /** @var string list|grid */
    public string $viewMode = 'list';

    /** Bumped when Reverb pushes server updates so the list re-queries from the database. */
    public int $serverListEpoch = 0;

    public ?string $deleteModalServerId = null;

    public string $deleteConfirmName = '';

    /** now|scheduled */
    public string $removeMode = 'now';

    public string $scheduledRemovalDate = '';

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'created_at';
        $this->statusFilter = '';
        $this->viewMode = 'list';
    }

    #[On('server-state-updated')]
    public function onServerStateUpdated(string $organizationId): void
    {
        $org = auth()->user()->currentOrganization();
        if (! $org || $org->id !== $organizationId) {
            return;
        }

        $this->serverListEpoch++;
    }

    public function openRemoveServerModal(string $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        $this->authorize('delete', $server);
        $this->deleteModalServerId = $serverId;
        $this->deleteConfirmName = '';
        $this->removeMode = 'now';
        $defaultDays = (int) config('dply.server_scheduled_deletion_default_days', 7);
        $this->scheduledRemovalDate = now()->addDays($defaultDays)->toDateString();
        $this->resetServerRemovalFormFields();
        $this->resetValidation();
    }

    public function closeRemoveServerModal(): void
    {
        $this->deleteModalServerId = null;
        $this->deleteConfirmName = '';
        $this->removeMode = 'now';
        $this->scheduledRemovalDate = '';
        $this->resetServerRemovalFormFields();
        $this->resetValidation();
    }

    public function submitRemoveServer(DeleteServerAction $deleteServer): void
    {
        if ($this->deleteModalServerId === null) {
            return;
        }

        $server = Server::query()->findOrFail($this->deleteModalServerId);
        $this->authorize('delete', $server);

        if (! hash_equals($server->name, trim($this->deleteConfirmName))) {
            $this->addError('deleteConfirmName', __('The name does not match exactly.'));

            return;
        }

        if ($this->removeMode === 'scheduled') {
            $this->validate([
                'scheduledRemovalDate' => ['required', 'date'],
                'deletionReason' => ['nullable', 'string', 'max:2000'],
            ]);
            $at = Carbon::parse($this->scheduledRemovalDate, config('app.timezone'))->endOfDay();
            if ($at->lte(now())) {
                $this->addError('scheduledRemovalDate', __('Pick a date whose end is still in the future (app timezone).'));

                return;
            }

            $reason = trim($this->deletionReason);
            $meta = $server->meta ?? [];
            if ($reason !== '') {
                $meta['scheduled_deletion_reason'] = $reason;
            } else {
                unset($meta['scheduled_deletion_reason']);
            }

            $org = $server->organization;
            if ($org) {
                $auditNew = [
                    'scheduled_deletion_at' => $at->toIso8601String(),
                ];
                if ($reason !== '') {
                    $auditNew['reason'] = $reason;
                }
                audit_log($org, auth()->user(), 'server.deletion_scheduled', $server, null, $auditNew);
            }

            $server->update([
                'scheduled_deletion_at' => $at,
                'meta' => $meta,
            ]);
            $this->notifyOrgAdminsOfScheduledRemoval($server->fresh(['organization']), $at, $reason !== '' ? $reason : null);
            $this->closeRemoveServerModal();
            $this->serverListEpoch++;
            session()->flash('success', __(':name is scheduled for removal at the end of :date.', [
                'name' => $server->name,
                'date' => $at->toFormattedDateString(),
            ]));

            return;
        }

        if (ServerRemovalAdvisor::hasRunningDeployments($server)) {
            $this->addError('removeMode', __('Finish or cancel running deployments on this server\'s sites before removing it.'));

            return;
        }

        $summary = ServerRemovalAdvisor::summary($server);
        $this->validate($this->immediateServerRemovalRules($summary));

        $reason = trim($this->deletionReason);
        $auditExtras = ['immediate' => true];
        if ($reason !== '') {
            $auditExtras['reason'] = $reason;
        }

        $actor = auth()->user();
        $emailContext = __('Removed by :name (:email) from the servers list.', [
            'name' => $actor->name,
            'email' => $actor->email,
        ]);

        $this->closeRemoveServerModal();
        $deleteServer->execute($server, $actor, $auditExtras, $emailContext);
        $this->serverListEpoch++;
        session()->flash('success', __('Server removed.'));
    }

    public function cancelScheduledServerRemoval(string $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        $this->authorize('delete', $server);
        if ($server->scheduled_deletion_at === null) {
            return;
        }

        $org = $server->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'server.deletion_schedule_cancelled', $server, [
                'scheduled_deletion_at' => $server->scheduled_deletion_at->toIso8601String(),
            ], null);
        }

        $meta = $server->meta ?? [];
        unset($meta['scheduled_deletion_reason']);
        $server->update([
            'scheduled_deletion_at' => null,
            'meta' => $meta,
        ]);
        $this->serverListEpoch++;
        session()->flash('success', __('Scheduled removal was cancelled.'));
    }

    /**
     * @return Collection<string, Collection<int, Server>>
     */
    protected function groupedServers(Collection $servers): Collection
    {
        return $servers
            ->groupBy(function (Server $server): string {
                if ($server->team_id !== null && $server->relationLoaded('team') && $server->team !== null) {
                    return $server->team->name;
                }
                if ($server->organization_id !== null && $server->relationLoaded('organization') && $server->organization !== null) {
                    return $server->organization->name;
                }

                return __('Personal');
            })
            ->sortKeys();
    }

    protected function baseQuery(): ?Builder
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            return null;
        }

        $query = Server::query()
            ->with(['sites', 'organization', 'team', 'workspace'])
            ->withCount('sites')
            ->where(function (Builder $q) use ($org) {
                $q->where('organization_id', $org->id)
                    ->orWhere(fn (Builder $q2) => $q2->whereNull('organization_id')->where('user_id', auth()->id()));
            });

        $team = auth()->user()->currentTeam();
        if ($team) {
            $query->where('team_id', $team->id);
        }

        return $query;
    }

    protected function applyFilters(Builder $query): Builder
    {
        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('provider', 'like', $like);
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return match ($this->sort) {
            'name' => $query->orderBy('name'),
            'status' => $query->orderBy('status')->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };
    }

    public function render(OrganizationInsightsMetricsService $insightsMetrics): View
    {
        $base = $this->baseQuery();
        $org = auth()->user()->currentOrganization();
        $hasServersInScope = $base !== null && (clone $base)->exists();
        $servers = $base
            ? $this->applyFilters(clone $base)->get()
            : collect();

        $groupedServers = $this->groupedServers($servers);

        $insightRollup = $servers->isNotEmpty()
            ? $insightsMetrics->perServerRollup($servers->pluck('id'))
            : collect();

        $summary = [
            'total' => $servers->count(),
            'ready' => $servers->where('status', Server::STATUS_READY)->count(),
            'attention' => $servers->filter(function (Server $server): bool {
                if ($server->scheduled_deletion_at !== null) {
                    return true;
                }

                if (in_array($server->status, [Server::STATUS_ERROR, Server::STATUS_DISCONNECTED], true)) {
                    return true;
                }

                return $server->status === Server::STATUS_READY
                    && $server->health_status === Server::HEALTH_UNREACHABLE;
            })->count(),
            'sites' => (int) $servers->sum('sites_count'),
        ];

        $openInsights = (int) $insightRollup->sum(fn (array $row): int => (int) ($row['open'] ?? 0));
        $hasProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;

        $deleteModalServer = $this->deleteModalServerId
            ? Server::query()->find($this->deleteModalServerId)
            : null;
        $deletionSummary = $deleteModalServer
            ? ServerRemovalAdvisor::summary($deleteModalServer)
            : null;

        return view('livewire.servers.index', [
            'hasServersInScope' => $hasServersInScope,
            'servers' => $servers,
            'groupedServers' => $groupedServers,
            'insightRollup' => $insightRollup,
            'summary' => $summary,
            'openInsights' => $openInsights,
            'hasProviderCredentials' => $hasProviderCredentials,
            'deleteModalServer' => $deleteModalServer,
            'deletionSummary' => $deletionSummary,
            'sortOptions' => config('user_preferences.server_sort_options', []),
            'statusOptions' => [
                '' => __('All statuses'),
                Server::STATUS_PENDING => __('Pending'),
                Server::STATUS_PROVISIONING => __('Provisioning'),
                Server::STATUS_READY => __('Ready'),
                Server::STATUS_ERROR => __('Error'),
                Server::STATUS_DISCONNECTED => __('Disconnected'),
            ],
        ]);
    }
}
