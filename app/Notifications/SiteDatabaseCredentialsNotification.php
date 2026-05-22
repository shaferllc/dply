<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when site scaffolding successfully provisions a database, IF
 * the org has `email_database_credentials_enabled` toggled on.
 *
 * Unlike server credentials (where the SSH private key is sensitive
 * enough to keep out of email), database passwords ARE delivered in
 * the body of this email — the operator opted in for the convenience,
 * and password-by-email is industry-standard for managed-DB workflows
 * (AWS RDS, DO managed databases, etc.).
 *
 * For SQLite sites, no credentials exist; the email body just confirms
 * the database file path so the operator knows where on disk it lives.
 */
class SiteDatabaseCredentialsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Site $site,
        public string $engine,
        /** Plain-text password for SQL engines; ignored when engine is sqlite3. */
        public ?string $password = null,
        public ?string $databaseName = null,
        public ?string $username = null,
        public ?string $sqlitePath = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = $this->site->name ?: $this->site->slug;
        $siteUrl = URL::route('sites.show', ['server' => $this->site->server_id, 'site' => $this->site->id]);

        if ($this->engine === 'sqlite3' || str_starts_with($this->engine, 'sqlite')) {
            return (new MailMessage)
                ->subject(sprintf('[%s] Database ready (SQLite)', $siteName))
                ->greeting(sprintf('Your "%s" site\'s database is ready.', $siteName))
                ->line('This site uses SQLite — there are no host/port/credentials.')
                ->line(sprintf('Database file: %s', $this->sqlitePath ?: 'database/database.sqlite (relative to deploy root)'))
                ->line('Laravel will create and manage the file automatically when migrations run.')
                ->action('Open site in dply', $siteUrl)
                ->salutation('— dply');
        }

        $host = $this->site->server?->ip_address ?: '127.0.0.1';
        $port = $this->engineDefaultPort($this->engine);

        return (new MailMessage)
            ->subject(sprintf('[%s] Database credentials', $siteName))
            ->greeting(sprintf('Your "%s" site\'s database is ready.', $siteName))
            ->line('Connection details:')
            ->line(sprintf('Engine: %s', $this->engine))
            ->line(sprintf('Host: %s', $host))
            ->line(sprintf('Port: %d', $port))
            ->line(sprintf('Database: %s', $this->databaseName ?: '—'))
            ->line(sprintf('Username: %s', $this->username ?: '—'))
            ->line(sprintf('Password: %s', $this->password ?: '—'))
            ->action('Open site in dply', $siteUrl)
            ->line('Treat this email as sensitive — anyone who can read it can access your database. Rotate the password from the dply dashboard if you suspect it has been seen by anyone other than you.')
            ->salutation('— dply');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'site_id' => $this->site->id,
            'engine' => $this->engine,
            'database_name' => $this->databaseName,
        ];
    }

    private function engineDefaultPort(string $engine): int
    {
        return match (true) {
            str_starts_with($engine, 'postgres') => 5432,
            default => 3306, // mysql / mariadb
        };
    }
}
