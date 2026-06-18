<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerFirewallApplyLog;
use App\Models\ServerFirewallAuditEvent;
use App\Models\User;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesFirewallActivity
{
    /**
     * Merge `firewallApplyLogs` and `firewallAuditEvents` into a single chronological timeline.
     * Audit's `EVENT_APPLY` rows are dropped because the matching apply log carries the same
     * fact in a richer shape (transcript + rules_hash + rule_count). Each item is a small
     * shape the view can branch on: `kind=apply` (expandable, with output) vs `kind=audit`
     * (compact one-liner).
     *
     * @return list<array{kind: string, at: \Carbon\Carbon|null, key: string, log?: ServerFirewallApplyLog, event?: ServerFirewallAuditEvent}>
     */
    /**
     * Activity timeline window size. Grows by {@see ACTIVITY_PAGE_SIZE} each time the operator
     * clicks "Load older"; capped at {@see ACTIVITY_MAX_VISIBLE} to keep render costs sane.
     */
    public int $activity_visible = 60;

    public bool $activity_exhausted = false;

    public function loadMoreFirewallActivity(): void
    {
        $this->activity_visible = min(
            self::ACTIVITY_MAX_VISIBLE,
            $this->activity_visible + self::ACTIVITY_PAGE_SIZE,
        );
    }

    protected function buildActivityItems(): array
    {
        // Pull a bit more than $activity_visible from each source so the merge has enough rows
        // to fill the window even after $activity_visible items get cut by sort order. We cap
        // each source at 2× visible because that's the worst case (all from one source).
        $sourceLimit = max($this->activity_visible * 2, self::ACTIVITY_PAGE_SIZE * 2);
        $applyLogs = $this->server->firewallApplyLogs()->limit($sourceLimit)->get();
        $auditEvents = $this->server->firewallAuditEvents()
            ->where('event', '!=', ServerFirewallAuditEvent::EVENT_APPLY)
            ->limit($sourceLimit)
            ->get();

        // Both rowsets reference the same `users` table; eager-loading via the relations
        // would issue two `users where id in (...)` queries that often return identical
        // rows (one operator does most of the work on a server). Pull the union once and
        // attach manually so we hit the table at most once per render.
        $userIds = $applyLogs->pluck('user_id')
            ->merge($auditEvents->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();
        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get()->keyBy('id');
        $applyLogs->each(fn ($log) => $log->setRelation('user', $log->user_id ? $users->get($log->user_id) : null));
        $auditEvents->each(fn ($ev) => $ev->setRelation('user', $ev->user_id ? $users->get($ev->user_id) : null));

        $items = [];
        foreach ($applyLogs as $log) {
            $items[] = [
                'kind' => 'apply',
                'at' => $log->created_at,
                'key' => 'apply-'.$log->id,
                'log' => $log,
            ];
        }
        foreach ($auditEvents as $ev) {
            $items[] = [
                'kind' => 'audit',
                'at' => $ev->created_at,
                'key' => 'audit-'.$ev->id,
                'event' => $ev,
            ];
        }

        usort($items, function (array $a, array $b): int {
            $at = $a['at']?->getTimestamp() ?? 0;
            $bt = $b['at']?->getTimestamp() ?? 0;

            return $bt <=> $at;
        });

        // "Exhausted" = we asked for more than we got from both sources, OR the cap is hit. The
        // view uses this to hide the "Load older" button when there's nothing more to show.
        $this->activity_exhausted = count($items) <= $this->activity_visible
            || $this->activity_visible >= self::ACTIVITY_MAX_VISIBLE;

        return array_slice($items, 0, $this->activity_visible);
    }
}
