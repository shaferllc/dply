<?php

namespace App\Console\Commands;

use App\Models\InsightDigestQueue;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProcessInsightDigestQueueCommand extends Command
{
    protected $signature = 'dply:process-insight-digest-queue {--weekly : Flush orgs with digest frequency weekly only}';

    protected $description = 'Send batched non-critical insight notifications (digest mode).';

    public function handle(): int
    {
        $weeklyRun = (bool) $this->option('weekly');

        $queued = InsightDigestQueue::query()
            ->with(['finding.server', 'organization'])
            ->get();

        if ($queued->isEmpty()) {
            return self::SUCCESS;
        }

        $byOrg = $queued->groupBy('organization_id');
        foreach ($byOrg as $orgId => $items) {
            /** @var Collection<int, InsightDigestQueue> $items */
            $org = Organization::query()->find($orgId);
            if ($org === null) {
                InsightDigestQueue::query()->whereIn('id', $items->pluck('id'))->delete();

                continue;
            }

            $freq = $org->mergedInsightsPreferences()['digest_frequency'] ?? 'daily';
            if (! in_array($freq, ['daily', 'weekly'], true)) {
                $freq = 'daily';
            }
            if ($weeklyRun && $freq !== 'weekly') {
                continue;
            }
            if (! $weeklyRun && $freq === 'weekly') {
                continue;
            }

            $lines = [];
            foreach ($items as $row) {
                $f = $row->finding;
                if ($f === null) {
                    continue;
                }
                $serverName = $f->server?->name ?? '?';
                $lines[] = sprintf(
                    '[%s] %s — %s',
                    strtoupper($f->severity),
                    $serverName,
                    Str::limit($f->title, 200)
                );
            }

            if ($lines !== []) {
                $recipients = $org->users()
                    ->wherePivotIn('role', ['owner', 'admin'])
                    ->get();
                $label = $weeklyRun ? __('Weekly insights digest (non-critical)') : __('Insights digest (non-critical)');
                $body = $label."\n\n".implode("\n", $lines);
                $subjectPrefix = $weeklyRun
                    ? '['.config('app.name').'] '.__('Weekly insights digest')
                    : '['.config('app.name').'] '.__('Insights digest');
                foreach ($recipients as $user) {
                    /** @var User $user */
                    if (! $user->email) {
                        continue;
                    }
                    Mail::raw(
                        $body,
                        fn ($m) => $m->to($user->email)->subject($subjectPrefix)
                    );
                }
            }

            InsightDigestQueue::query()->whereIn('id', $items->pluck('id'))->delete();
        }

        return self::SUCCESS;
    }
}
