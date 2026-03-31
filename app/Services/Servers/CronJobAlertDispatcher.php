<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Notifications\CronJobAlertNotification;

final class CronJobAlertDispatcher
{
    public function dispatchIfNeeded(Server $server, ServerCronJob $job, CronJobRunResult $result): void
    {
        $org = $server->organization;
        if ($org === null) {
            return;
        }

        $failure = $job->alert_on_failure
            && $result->exitCode !== null
            && $result->exitCode !== 0;

        $patternHit = false;
        if ($job->alert_on_pattern_match && is_string($job->alert_pattern) && $job->alert_pattern !== '') {
            set_error_handler(static fn () => true);
            $patternHit = @preg_match($job->alert_pattern, $result->output) === 1;
            restore_error_handler();
        }

        if (! $failure && ! $patternHit) {
            return;
        }

        $org->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->get()
            ->each(fn ($user) => $user->notify(new CronJobAlertNotification(
                $server,
                $job,
                $result,
                failure: $failure,
                patternHit: $patternHit,
            )));
    }
}
