<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Notifications\CronJobAlertNotification;
use App\Services\Notifications\NotificationPublisher;

final class CronJobAlertDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

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
            ->pipe(function ($users) use ($server, $job, $result, $failure, $patternHit, $org): void {
                $event = $this->publisher->publish(
                    eventKey: 'server.cron.alert',
                    subject: $server,
                    title: '['.config('app.name').'] Cron job alert on '.$server->name,
                    body: $failure
                        ? 'Cron job failed with exit code '.($result->exitCode ?? '?').'.'
                        : 'Cron job output matched the configured alert pattern.',
                    url: route('servers.cron', $server, absolute: true),
                    recipientUsers: $users->pluck('id')->all(),
                    metadata: [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'organization_name' => $org->name,
                        'cron_job_id' => $job->id,
                        'cron_job_description' => $job->description ?: \Illuminate\Support\Str::limit($job->command, 80),
                        'failure' => $failure,
                        'pattern_hit' => $patternHit,
                        'exit_code' => $result->exitCode,
                        'output_excerpt' => \Illuminate\Support\Str::limit($result->output, 2000),
                    ],
                );

                foreach ($users as $user) {
                    $user->notify(new CronJobAlertNotification($event));
                }
            });
    }
}
