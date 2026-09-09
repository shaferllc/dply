<?php

namespace App\Livewire\Organizations;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\AuditActionMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire resolves `get<Name>Property()` as `$this->name` and caches it for
 * the request; declared here so static analysis sees the same properties.
 *
 * @property-read LengthAwarePaginator<int, AuditLog> $auditLogs
 * @property-read array<string, int> $familyTotals
 * @property-read ?AuditLog $selectedLog
 */
#[Layout('layouts.app')]
class Activity extends Component
{
    use WithPagination;

    public Organization $organization;

    /** Active family filter id from {@see AuditActionMeta::FAMILIES} (`''` = no filter). */
    #[Url(as: 'family', except: '')]
    public string $family = '';

    /** Optional free-text search against `action` / `subject_summary`. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Who acted: `''` (anyone), `people`, or `system`. */
    #[Url(as: 'by', except: '')]
    public string $actor = '';

    /** Rows per page. URL-synced so deep-links round-trip the picker too. */
    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    /**
     * The row open in the detail pane. URL-synced so a specific event can be
     * linked straight to a reviewer. Empty means "the first row on this page",
     * resolved in {@see getSelectedLogProperty} — the pane is never blank.
     *
     * Audit log ids are ULIDs, so this is a string. The accordion it replaced
     * declared `list<int>` and rendered `toggleRow(01m0myf7…)` into the DOM,
     * which is not a valid expression — the before/after diff never opened.
     */
    #[Url(as: 'event', except: '')]
    public string $selectedId = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);
        $this->organization = $organization;
    }

    public function select(string $id): void
    {
        $this->selectedId = $id;
    }

    public function clearFilters(): void
    {
        $this->family = '';
        $this->search = '';
        $this->actor = '';
        $this->resetPage();
    }

    /**
     * Any filter change can strand you on a page that no longer exists, and
     * strand the pane on a row that is no longer listed — reset both.
     */
    public function updatedSearch(): void
    {
        $this->resetSelection();
    }

    public function updatedFamily(): void
    {
        $this->resetSelection();
    }

    public function updatedActor(): void
    {
        $this->resetSelection();
    }

    public function updatedPerPage(): void
    {
        $this->resetSelection();
    }

    private function resetSelection(): void
    {
        $this->selectedId = '';
        $this->resetPage();
    }

    /**
     * Paginated audit rows for this org, filtered by family + free-text
     * search. The family filter applies a set of action-prefix predicates
     * via {@see AuditActionMeta::family} so the resolver and the query
     * stay in sync (one source of truth for "what counts as `server`").
     */
    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function getAuditLogsProperty(): LengthAwarePaginator
    {
        // Eager-load the morphTo subject so the subject_summary accessor
        // doesn't lazy-load one server/credential/site per row (N+1).
        // Laravel batches morphTo loads by subject_type, so repeated
        // subjects collapse to one whereIn per type.
        $query = $this->organization->auditLogs()
            ->with(['user', 'subject'])
            ->latest();

        $this->applyFamilyFilter($query, $this->family);

        // "System" is the absence of an actor — the audit writer leaves
        // user_id null for anything the platform did on its own.
        if ($this->actor === 'people') {
            $query->whereNotNull('user_id');
        } elseif ($this->actor === 'system') {
            $query->whereNull('user_id');
        }

        if ($this->search !== '') {
            $needle = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->where('action', 'like', $needle)
                    ->orWhere('subject_summary', 'like', $needle);
            });
        }

        $perPage = max(10, min(100, $this->perPage));

        return $query->paginate($perPage);
    }

    /**
     * The record shown in the detail pane. Falls back to the first row on the
     * current page so the pane always has something in it, and re-queries
     * through the org relation so a hand-edited `?event=` can only ever
     * address this organization's own rows.
     */
    public function getSelectedLogProperty(): ?AuditLog
    {
        if ($this->selectedId === '') {
            return $this->auditLogs->first();
        }

        return $this->organization->auditLogs()
            ->with(['user', 'subject'])
            ->find($this->selectedId)
            ?? $this->auditLogs->first();
    }

    /**
     * Per-family totals scoped to this org. Drives the count chips on
     * each filter pill — they show "Servers · 42" so an admin can spot
     * spikes at a glance.
     *
     * Computed in a single conditional-aggregation query rather than one
     * COUNT per family (previously 14 round-trips, one of which exactly
     * duplicated the paginator's count for the unfiltered view).
     *
     * @return array<string, int>
     */
    public function getFamilyTotalsProperty(): array
    {
        $conditions = self::familyConditions();

        $selects = ['COUNT(*) as total_all'];
        foreach ($conditions as $id => $sql) {
            $selects[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as total_{$id}";
        }

        $totalsQuery = AuditLog::query()
            ->where('organization_id', $this->organization->id);

        // Respects the actor filter but not the search box: actor is a
        // persistent choice, so its counts must reconcile with the list;
        // search changes per keystroke and would make them flicker.
        if ($this->actor === 'people') {
            $totalsQuery->whereNotNull('user_id');
        } elseif ($this->actor === 'system') {
            $totalsQuery->whereNull('user_id');
        }

        $row = $totalsQuery
            ->selectRaw(implode(', ', $selects))
            ->toBase()
            ->first();

        $totals = ['' => (int) ($row->total_all ?? 0)];
        foreach (array_keys($conditions) as $id) {
            $totals[$id] = (int) ($row->{"total_{$id}"} ?? 0);
        }

        return $totals;
    }

    /**
     * Apply the family filter to the listing query. Shares its predicate
     * definitions with {@see familyConditions} so the chip counts and the
     * filtered list can never drift apart.
     *
     * Accepts either a {@see Builder} or a {@see HasMany} relation; chains
     * on Eloquent relations stay on the relation object rather than
     * promoting to Builder, so this method has to handle both.
     */
    /**
     * @param  Builder<AuditLog>|HasMany<AuditLog, Organization>  $query
     */
    private function applyFamilyFilter(Builder|HasMany $query, string $family): void
    {
        $conditions = self::familyConditions();
        if (isset($conditions[$family])) {
            $query->whereRaw('('.$conditions[$family].')');
        }
    }

    /**
     * Family id → raw SQL predicate over the `action` column. The strings
     * are fully static (no user input), so whereRaw / selectRaw use them
     * safely. Keep buckets in lock-step with {@see AuditActionMeta::family},
     * which derives the same families for per-row icons.
     *
     * @return array<string, string>
     */
    private static function familyConditions(): array
    {
        return [
            'server' => "action LIKE 'server.%'",
            'site' => "action LIKE 'site.%' AND action NOT LIKE 'site.edge.%'",
            'edge' => "action LIKE 'site.edge.%'",
            'project' => "action LIKE 'project.%'",
            'team' => "action LIKE 'team.%'",
            'billing' => "action LIKE 'billing.%'",
            'security' => "(action LIKE 'api_token.%' OR action LIKE 'invitation.%' OR action LIKE 'notification_channel.%')",
            'org' => "action LIKE 'organization.%'",
            'backup' => "action LIKE 'backup.%'",
            'insight' => "action LIKE 'insight.%'",
            'import' => "action LIKE 'import.%'",
            'background' => "action LIKE 'queue_worker.%'",
            'other' => "action NOT LIKE 'server.%' AND action NOT LIKE 'site.%' "
                ."AND action NOT LIKE 'project.%' AND action NOT LIKE 'team.%' "
                ."AND action NOT LIKE 'billing.%' AND action NOT LIKE 'api_token.%' "
                ."AND action NOT LIKE 'invitation.%' AND action NOT LIKE 'notification_channel.%' "
                ."AND action NOT LIKE 'organization.%' AND action NOT LIKE 'backup.%' "
                ."AND action NOT LIKE 'insight.%' AND action NOT LIKE 'import.%' "
                ."AND action NOT LIKE 'queue_worker.%'",
        ];
    }

    public function render(): View
    {
        return view('livewire.organizations.activity', [
            'families' => AuditActionMeta::FAMILIES,
        ]);
    }
}
