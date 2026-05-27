<?php

namespace App\Livewire\Organizations;

use App\Models\Organization;
use App\Support\AuditActionMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Activity extends Component
{
    public Organization $organization;

    /** Active family filter id from {@see AuditActionMeta::FAMILIES} (`''` = no filter). */
    #[Url(as: 'family', except: '')]
    public string $family = '';

    /** Optional free-text search against `action` / `subject_summary`. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Audit log row IDs the operator has expanded inline to view the
     * old/new value diff. Kept in the component (not the URL) — short-
     * lived UI state.
     *
     * @var list<int>
     */
    public array $expandedIds = [];

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);
        $this->organization = $organization;
    }

    public function setFamily(string $family): void
    {
        $valid = array_column(AuditActionMeta::FAMILIES, 'id');
        $this->family = in_array($family, $valid, true) ? $family : '';
    }

    public function clearFilters(): void
    {
        $this->family = '';
        $this->search = '';
    }

    public function toggleRow(int $id): void
    {
        if (in_array($id, $this->expandedIds, true)) {
            $this->expandedIds = array_values(array_diff($this->expandedIds, [$id]));

            return;
        }

        $this->expandedIds[] = $id;
    }

    /**
     * Latest 200 audit rows for this org, filtered by family + free-text
     * search. The family filter applies a set of action-prefix predicates
     * via {@see AuditActionMeta::family} so the resolver and the query
     * stay in sync (one source of truth for "what counts as `server`").
     */
    public function getAuditLogsProperty(): Collection
    {
        $query = $this->organization->auditLogs()
            ->with('user')
            ->latest()
            ->limit(200);

        $this->applyFamilyFilter($query, $this->family);

        if ($this->search !== '') {
            $needle = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->where('action', 'like', $needle)
                    ->orWhere('subject_summary', 'like', $needle);
            });
        }

        return $query->get();
    }

    /**
     * Per-family totals scoped to this org. Drives the count chips on
     * each filter pill — they show "Servers · 42" so an admin can spot
     * spikes at a glance. Excludes the search box so the totals don't
     * jump around as you type.
     *
     * @return array<string, int>
     */
    public function getFamilyTotalsProperty(): array
    {
        $totals = ['' => (int) $this->organization->auditLogs()->count()];

        foreach (AuditActionMeta::FAMILIES as $f) {
            $q = $this->organization->auditLogs()->newQuery();
            $this->applyFamilyFilter($q, $f['id']);
            $totals[$f['id']] = (int) $q->count();
        }

        return $totals;
    }

    /**
     * Map a family id to action-prefix predicates. Keep this in lock-step
     * with {@see AuditActionMeta::family} — same buckets, but here we
     * express them in SQL so filtering can happen at the DB.
     *
     * Accepts either a {@see Builder} or a {@see HasMany} relation; chains
     * on Eloquent relations stay on the relation object rather than
     * promoting to Builder, so this method has to handle both.
     */
    private function applyFamilyFilter(Builder|HasMany $query, string $family): void
    {
        match ($family) {
            'server' => $query->where('action', 'like', 'server.%'),
            'site' => $query->where('action', 'like', 'site.%')
                ->where('action', 'not like', 'site.edge.%'),
            'edge' => $query->where('action', 'like', 'site.edge.%'),
            'project' => $query->where('action', 'like', 'project.%'),
            'team' => $query->where('action', 'like', 'team.%'),
            'billing' => $query->where('action', 'like', 'billing.%'),
            'security' => $query->where(function (Builder $q): void {
                $q->where('action', 'like', 'api_token.%')
                    ->orWhere('action', 'like', 'invitation.%')
                    ->orWhere('action', 'like', 'notification_channel.%');
            }),
            'org' => $query->where('action', 'like', 'organization.%'),
            'backup' => $query->where('action', 'like', 'backup.%'),
            'insight' => $query->where('action', 'like', 'insight.%'),
            'import' => $query->where('action', 'like', 'import.%'),
            'background' => $query->where('action', 'like', 'queue_worker.%'),
            'other' => $query->where(function (Builder $q): void {
                $q->where('action', 'not like', 'server.%')
                    ->where('action', 'not like', 'site.%')
                    ->where('action', 'not like', 'project.%')
                    ->where('action', 'not like', 'team.%')
                    ->where('action', 'not like', 'billing.%')
                    ->where('action', 'not like', 'api_token.%')
                    ->where('action', 'not like', 'invitation.%')
                    ->where('action', 'not like', 'notification_channel.%')
                    ->where('action', 'not like', 'organization.%')
                    ->where('action', 'not like', 'backup.%')
                    ->where('action', 'not like', 'insight.%')
                    ->where('action', 'not like', 'import.%')
                    ->where('action', 'not like', 'queue_worker.%');
            }),
            default => null,
        };
    }

    public function render(): View
    {
        return view('livewire.organizations.activity', [
            'families' => AuditActionMeta::FAMILIES,
        ]);
    }
}
