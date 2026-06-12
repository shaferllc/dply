<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\QuickDownload;
use App\Models\User;
use App\Notifications\QuickDownloadFailedNotification;
use App\Notifications\QuickDownloadReadyNotification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Tells the requesting user their quick download is ready (or failed) on BOTH
 * channels: an in-app inbox item (via {@see NotificationPublisher}) for users
 * still in the app, and a transactional email for those who walked away. The
 * ready link is a signed, login-gated route to {@see QuickDownloadController},
 * valid until the staging window closes.
 */
final class QuickDownloadNotifier
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function ready(QuickDownload $row): void
    {
        $label = self::label($row);
        $url = $this->signedUrl($row);

        $this->publishInApp(
            $row,
            'quick_download.ready',
            __('Your download is ready: :label', ['label' => $label]),
            __('Click to download — the link works once and expires in 4 hours.'),
            $url,
        );

        $user = $row->requestedBy;
        if ($user instanceof User) {
            Notification::send($user, new QuickDownloadReadyNotification($row, $label, $url));
        }
    }

    public function failed(QuickDownload $row, bool $overCap = false): void
    {
        $label = self::label($row);
        $reason = (string) ($row->error_message ?? '');
        $backupsUrl = $this->backupsUrl($row);

        $this->publishInApp(
            $row,
            'quick_download.failed',
            __('Download failed: :label', ['label' => $label]),
            $reason !== '' ? $reason : __('The download could not be prepared.'),
            $backupsUrl,
        );

        $user = $row->requestedBy;
        if ($user instanceof User) {
            Notification::send($user, new QuickDownloadFailedNotification($row, $label, $reason, $backupsUrl, $overCap));
        }
    }

    /**
     * Human label for an artifact, e.g. "Everything (files + DB + .env)" or
     * "database dump (shop)". Shared by the inbox item, email subject, and toasts.
     */
    public static function label(QuickDownload $row): string
    {
        if ($row->kind === QuickDownload::KIND_SITE) {
            return match ($row->artifact) {
                'bundle' => __('Everything (files + DB + .env)'),
                'files' => __('Site files'),
                'env' => __('.env file'),
                'vhost' => __('Webserver config'),
                'logs' => __('Logs'),
                'home' => __('Full home directory'),
                default => __('Site download'),
            };
        }

        $name = (string) ($row->serverDatabase?->name ?? $row->meta['name'] ?? '');

        return $name !== ''
            ? __('Database dump (:name)', ['name' => $name])
            : __('Database dump');
    }

    private function publishInApp(QuickDownload $row, string $eventKey, string $title, string $body, string $url): void
    {
        $user = $row->requestedBy;
        if (! $user instanceof User) {
            return;
        }

        // Target the single requester (not the org's stakeholders). The default
        // registry definition is in-app only, so this drops one inbox item and
        // fires no operator-channel fan-out.
        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $row->server,
            title: $title,
            body: $body,
            url: $url,
            metadata: ['quick_download_id' => (string) $row->id],
            recipientUsers: [$user],
        );
    }

    private function signedUrl(QuickDownload $row): string
    {
        $expires = $row->expires_at ?? now()->addMinutes((int) config('backup_staging.ttl_minutes', 240));

        return URL::temporarySignedRoute(
            'quick-download.fetch',
            $expires,
            ['quickDownload' => $row->id],
        );
    }

    private function backupsUrl(QuickDownload $row): string
    {
        return $row->site_id !== null
            ? route('sites.backups', [$row->server_id, $row->site_id], absolute: true)
            : route('servers.backups', $row->server_id, absolute: true);
    }
}
