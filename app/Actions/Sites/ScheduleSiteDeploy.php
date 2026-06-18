<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Modules\Deploy\Console\RunDueScheduledDeploysCommand;
use App\Models\ScheduledDeploy;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Creates / cancels / reads a site's one-off DELAYED deploy. Shared by the
 * Deploy-tab panel and the persistent DeployControl so both schedule the same
 * way. The control-plane {@see RunDueScheduledDeploysCommand}
 * tick fires the deploy when it comes due.
 */
class ScheduleSiteDeploy
{
    /** The site's pending delayed deploy, if any. */
    public function pendingFor(Site $site): ?ScheduledDeploy
    {
        return ScheduledDeploy::query()
            ->where('site_id', $site->id)
            ->pending()
            ->orderByDesc('run_at')
            ->first();
    }

    /**
     * Schedule a deploy for `$when` — a number of minutes from now (preset) or
     * an absolute datetime string (custom picker). Replaces any existing pending
     * delayed deploy (one queued at a time). Returns null when the time is
     * invalid / not in the future.
     */
    public function schedule(Site $site, string $when, ?string $userId): ?ScheduledDeploy
    {
        $runAt = $this->parseWhen($when);
        if ($runAt === null) {
            return null;
        }

        ScheduledDeploy::query()
            ->where('site_id', $site->id)
            ->pending()
            ->each(fn (ScheduledDeploy $d) => $d->cancel());

        return ScheduledDeploy::create([
            'site_id' => $site->id,
            'user_id' => $userId,
            'run_at' => $runAt,
            'status' => ScheduledDeploy::STATUS_PENDING,
        ]);
    }

    public function cancelPending(Site $site): void
    {
        $this->pendingFor($site)?->cancel();
    }

    /** Resolve a schedule request into an absolute future time, or null. */
    public function parseWhen(string $when): ?Carbon
    {
        $when = trim($when);
        if ($when === '') {
            return null;
        }

        // Numeric → that many minutes from now (preset buttons).
        if (ctype_digit($when)) {
            $minutes = (int) $when;

            return $minutes > 0 ? now()->addMinutes($minutes) : null;
        }

        // Otherwise an absolute datetime (the custom picker). Must be future.
        try {
            $at = Carbon::parse($when);
        } catch (\Throwable) {
            return null;
        }

        return $at->isAfter(now()->addSeconds(30)) ? $at : null;
    }
}
