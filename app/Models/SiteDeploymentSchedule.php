<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Deploy\Console\RunDueDeploymentSchedulesCommand;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\Concerns\DescribesCronExpression;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A recurring, cron-scheduled deploy for a site. Evaluated on the control-plane
 *                      Laravel scheduler (see {@see RunDueDeploymentSchedulesCommand}),
 *                      which dispatches {@see RunSiteDeploymentJob} when a schedule is due —
 *                      deploys are control-plane orchestrated (SSH out), so this is NOT a remote crontab.
 * @property string $consecutive_failures
 * @property string $cron_expression
 * @property string $git_branch
 * @property string $is_active
 * @property ?Carbon $last_run_at
 * @property string $notify_on_failure
 * @property ?string $server_id
 * @property ?string $site_id
 * @property string $timezone
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteDeploymentSchedule extends Model
{
    use DescribesCronExpression, HasUlids;

    protected $table = 'site_deployment_schedules';

    /** Auto-pause after this many consecutive failed scheduled deploys. */
    public const MAX_CONSECUTIVE_FAILURES = 5;

    protected $fillable = [
        'site_id',
        'server_id',
        'cron_expression',
        'timezone',
        'git_branch',
        'is_active',
        'notify_on_failure',
        'consecutive_failures',
        'last_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'notify_on_failure' => 'boolean',
        'consecutive_failures' => 'integer',
        'last_run_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Whether this schedule's next cron tick has elapsed since it last ran (or
     * since `$now` minus one minute on first run). Called once a minute by the
     * dispatcher, so a one-minute look-back means we never miss a tick.
     */
    public function isDue(Carbon $now): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $cron = new CronExpression($this->cron_expression);
        $tz = $this->timezone ?: config('app.timezone', 'UTC');

        // Anchor from the last run (or one tick ago on first run) and ask the
        // parser for the next scheduled time after that anchor.
        $anchor = $this->last_run_at ?? $now->copy()->subMinute();
        $next = Carbon::instance($cron->getNextRunDate($anchor->copy()->setTimezone($tz)))
            ->setTimezone($tz);

        return $next->lessThanOrEqualTo($now->copy()->setTimezone($tz));
    }

    public function recordRun(Carbon $now): void
    {
        $this->forceFill(['last_run_at' => $now])->save();
    }
}
