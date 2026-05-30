<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Models\ServerDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when a tracked server database is created in the workspace, IF the
 * org has `email_database_credentials_enabled` toggled on.
 *
 * Mirrors {@see SiteDatabaseCredentialsNotification}: passwords are included
 * in the email body when the operator opted in at the organization level.
 */
class ServerDatabaseCredentialsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public ServerDatabase $database,
        /** Plain-text password for SQL engines; null for SQLite. */
        public ?string $password = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serverName = $this->server->name ?: __('Server');
        $dbName = $this->database->name;
        $workspaceUrl = URL::route('servers.databases', $this->server, absolute: true);

        if ($this->database->engine === 'sqlite') {
            return (new MailMessage)
                ->subject(sprintf('[%s] Database ready (SQLite)', $serverName))
                ->greeting(sprintf('Database "%s" on %s is ready.', $dbName, $serverName))
                ->line(__('This database is file-based — no username or password.'))
                ->line(sprintf(__('File path: %s'), $this->database->host ?: '—'))
                ->action(__('Open databases in dply'), $workspaceUrl)
                ->salutation('— dply');
        }

        $host = $this->database->host ?: $this->server->ip_address ?: '127.0.0.1';
        $engineLabel = match ($this->database->engine) {
            'postgres' => 'PostgreSQL',
            'mysql' => 'MySQL / MariaDB',
            default => $this->database->engine,
        };

        return (new MailMessage)
            ->subject(sprintf('[%s] Database credentials — %s', $serverName, $dbName))
            ->greeting(sprintf('Database "%s" on %s is ready.', $dbName, $serverName))
            ->line(__('Connection details:'))
            ->line(sprintf(__('Engine: %s'), $engineLabel))
            ->line(sprintf(__('Host: %s'), $host))
            ->line(sprintf(__('Database: %s'), $dbName))
            ->line(sprintf(__('Username: %s'), $this->database->username ?: '—'))
            ->line(sprintf(__('Password: %s'), $this->password ?: '—'))
            ->action(__('Open databases in dply'), $workspaceUrl)
            ->line(__('Treat this email as sensitive — anyone who can read it can access your database. Rotate the password from the server workspace if you suspect it has been seen by anyone other than you.'))
            ->salutation('— dply');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_database_id' => $this->database->id,
            'database_name' => $this->database->name,
            'engine' => $this->database->engine,
        ];
    }
}
