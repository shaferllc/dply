<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\AuditLog;
use App\Models\OrganizationInvitation;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceActivity extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.activity';

    use InteractsWithServerWorkspace;
    use WithPagination;

    public string $tab = 'feed';

    /** Cached site IDs for the server to avoid duplicate queries */
    private ?array $cachedSiteIds = null;

    /**
     * Filter by derived action category. See {@see categorize()} for the
     * vocabulary; '' means no filter.
     */
    #[Url(as: 'cat', except: '')]
    public string $category = '';

    /** Filter by acting user id; '' means no filter. */
    #[Url(as: 'user', except: '')]
    public string $userId = '';

    /** Date range key — see {@see rangeDays()} for the vocabulary. */
    #[Url(as: 'range', except: '30d')]
    public string $range = '30d';

    /** Categories rendered in the filter chips and used for trends bucketing. */
    public const CATEGORIES = [
        'insights' => 'Insights',
        'firewall' => 'Firewall',
        'ssh' => 'SSH keys',
        'caches' => 'Caches',
        'databases' => 'Databases',
        'deploys' => 'Deploys',
        'background' => 'Background',
        'server' => 'Server',
        'site' => 'Site',
        'other' => 'Other',
    ];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        // Deep links from sibling workspace pages can pre-apply category / range filters
        // via the query string (e.g. servers.activity?category=background) so operators
        // land on a focused view without an extra click.
        $category = request()->query('category');
        if (is_string($category) && array_key_exists($category, self::CATEGORIES)) {
            $this->category = $category;
        }
        $range = request()->query('range');
        if (is_string($range) && in_array($range, ['24h', '7d', '30d', '90d'], true)) {
            $this->range = $range;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['feed', 'trends'], true) ? $tab : 'feed';
    }

    public function setCategory(string $category): void
    {
        $this->category = array_key_exists($category, self::CATEGORIES) ? $category : '';
        $this->resetPage();
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d', '90d'], true) ? $range : '30d';
        $this->resetPage();
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->category = '';
        $this->userId = '';
        $this->range = '30d';
        $this->resetPage();
    }

    /**
     * Bucket an action into a UI-facing category. Derived from the action
     * string prefix so we don't need a category column on audit_logs.
     */
    public static function categorize(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'insight.') => 'insights',
            str_starts_with($action, 'server.firewall.') => 'firewall',
            str_starts_with($action, 'server.ssh_keys.') => 'ssh',
            str_starts_with($action, 'server.caches.') => 'caches',
            str_starts_with($action, 'server.databases.') => 'databases',
            str_starts_with($action, 'site.deploy.'),
            str_starts_with($action, 'project.deploy.') => 'deploys',
            str_starts_with($action, 'backup.schedule.'),
            str_starts_with($action, 'queue_worker.') => 'background',
            str_starts_with($action, 'server.') => 'server',
            str_starts_with($action, 'site.') => 'site',
            default => 'other',
        };
    }

    /**
     * Apply the active category filter as a set of LIKE predicates.
     * Kept here (not in the model) because category is a UI concept that
     * doesn't exist on the row.
     */
    protected function applyCategoryFilter(Builder $query, string $category): Builder
    {
        return match ($category) {
            'insights' => $query->where('action', 'like', 'insight.%'),
            'firewall' => $query->where('action', 'like', 'server.firewall.%'),
            'ssh' => $query->where('action', 'like', 'server.ssh_keys.%'),
            'caches' => $query->where('action', 'like', 'server.caches.%'),
            'databases' => $query->where('action', 'like', 'server.databases.%'),
            'deploys' => $query->where(function (Builder $q): void {
                $q->where('action', 'like', 'site.deploy.%')
                    ->orWhere('action', 'like', 'project.deploy.%');
            }),
            'background' => $query->where(function (Builder $q): void {
                $q->where('action', 'like', 'backup.schedule.%')
                    ->orWhere('action', 'like', 'queue_worker.%');
            }),
            // 'server' is the leftover server.* bucket — exclude its
            // already-categorized siblings so chips are mutually exclusive.
            'server' => $query
                ->where('action', 'like', 'server.%')
                ->where('action', 'not like', 'server.firewall.%')
                ->where('action', 'not like', 'server.ssh_keys.%')
                ->where('action', 'not like', 'server.caches.%')
                ->where('action', 'not like', 'server.databases.%'),
            'site' => $query
                ->where('action', 'like', 'site.%')
                ->where('action', 'not like', 'site.deploy.%'),
            'other' => $query
                ->where('action', 'not like', 'insight.%')
                ->where('action', 'not like', 'server.%')
                ->where('action', 'not like', 'site.%')
                ->where('action', 'not like', 'project.deploy.%')
                ->where('action', 'not like', 'backup.schedule.%')
                ->where('action', 'not like', 'queue_worker.%'),
            default => $query,
        };
    }

    public static function rangeDays(string $range): int
    {
        return match ($range) {
            '24h' => 1,
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };
    }

    /**
     * Get cached site IDs for this server to avoid duplicate queries.
     */
    protected function getSiteIds(): array
    {
        if ($this->cachedSiteIds === null) {
            $this->cachedSiteIds = $this->server->sites()->pluck('id')->all();
        }

        return $this->cachedSiteIds;
    }

    /**
     * Base query for audit_logs scoped to this server (and its sites),
     * within the active date range. Both feed and trends start from this.
     */
    protected function baseQuery(): Builder
    {
        $siteIds = $this->getSiteIds();
        $since = Carbon::now()->subDays(self::rangeDays($this->range));

        return AuditLog::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('created_at', '>=', $since)
            ->where(function (Builder $q) use ($siteIds): void {
                $q->where(function (Builder $q): void {
                    $q->where('subject_type', Server::class)
                        ->where('subject_id', $this->server->id);
                });
                if ($siteIds !== []) {
                    $q->orWhere(function (Builder $q) use ($siteIds): void {
                        $q->where('subject_type', Site::class)
                            ->whereIn('subject_id', $siteIds);
                    });
                }
            });
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function getEventsProperty(): LengthAwarePaginator
    {
        $query = $this->baseQuery()
            ->with('user:id,name,email')
            ->with(['subject' => function ($morphTo) {
                $morphTo->morphWith([
                    Server::class => [],
                    Site::class => [],
                    Workspace::class => [],
                    Team::class => [],
                    OrganizationInvitation::class => [],
                    SiteDeployment::class => [],
                ]);
            }]);

        if ($this->category !== '') {
            $this->applyCategoryFilter($query, $this->category);
        }
        if ($this->userId !== '') {
            $query->where('user_id', $this->userId);
        }

        return $query->latest('created_at')->paginate(25);
    }

    /**
     * Per-day, per-category counts for the trends chart. Returns up to
     * `rangeDays($range)` rows of date strings, each with a count per
     * category. Computed in PHP from the raw rows because category
     * derivation is string-prefix logic, not a stored column.
     *
     * @return array{
     *     dates: list<string>,
     *     totals: array<string, int>,
     *     buckets: list<array{date: string, total: int, by_category: array<string, int>}>
     * }
     */
    public function getTrendsProperty(): array
    {
        $rows = $this->baseQuery()
            ->select(['action', 'created_at'])
            ->orderBy('created_at')
            ->get();

        $days = self::rangeDays($this->range);
        $start = Carbon::now()->subDays($days - 1)->startOfDay();
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $start->copy()->addDays($i)->toDateString();
        }

        $byDate = array_fill_keys($dates, []);
        $totals = array_fill_keys(array_keys(self::CATEGORIES), 0);

        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at)->toDateString();
            if (! isset($byDate[$date])) {
                continue;
            }
            $cat = self::categorize((string) $row->action);
            $byDate[$date][$cat] = ($byDate[$date][$cat] ?? 0) + 1;
            $totals[$cat] = ($totals[$cat] ?? 0) + 1;
        }

        $buckets = [];
        foreach ($dates as $date) {
            $by = $byDate[$date];
            $buckets[] = [
                'date' => $date,
                'total' => array_sum($by),
                'by_category' => $by,
            ];
        }

        return [
            'dates' => $dates,
            'totals' => array_filter($totals, fn (int $n): bool => $n > 0),
            'buckets' => $buckets,
        ];
    }

    /**
     * Distinct actors who have logged events in the current scope/range,
     * for the actor filter dropdown.
     *
     * @return Collection<int, array{id: string, name: string}>
     */
    public function getActorsProperty(): Collection
    {
        $userIds = (clone $this->baseQuery())
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (string) $u->id, 'name' => (string) $u->name]);
    }

    public function render(): View
    {
        if (in_array('activity', config('server_workspace.coming_soon_keys', []), true)) {
            return view('livewire.servers.workspace-activity-preview', ['server' => $this->server]);
        }

        return view('livewire.servers.workspace-activity');
    }
}
