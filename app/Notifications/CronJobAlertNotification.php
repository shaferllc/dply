<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Services\Servers\CronJobRunResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CronJobAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public ServerCronJob $cronJob,
        public CronJobRunResult $result,
        public bool $failure,
        public bool $patternHit,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reason = $this->failure
            ? __('Non-zero exit code (:code).', ['code' => (string) ($this->result->exitCode ?? '?')])
            : __('Output matched your alert pattern.');

        return (new MailMessage)
            ->subject(__('[:app] Cron job alert: :server', ['app' => config('app.name'), 'server' => $this->server->name]))
            ->line(__('Cron job “:desc” on server :server.', [
                'desc' => $this->cronJob->description ?: Str::limit($this->cronJob->command, 80),
                'server' => $this->server->name,
            ]))
            ->line($reason)
            ->line(__('Exit code: :code', ['code' => (string) ($this->result->exitCode ?? '—')]))
            ->line(Str::limit($this->result->output, 2000));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Cron job alert'),
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'cron_job_id' => $this->cronJob->id,
            'exit_code' => $this->result->exitCode,
            'failure' => $this->failure,
            'pattern_hit' => $this->patternHit,
        ];
    }
}
