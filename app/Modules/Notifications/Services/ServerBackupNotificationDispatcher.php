<?php

namespace App\Modules\Notifications\Services;

use App\Events\Servers\BackupStatusBroadcast;
use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Servers\WorkspaceBackups;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerBackupNotificationKeys;

/**
 * Publishes notifications for server-scoped backup changes (database / site-files
 * runs, completions, failures, deletes, and schedule CRUD), fired from the backups
 * workspace ({@see WorkspaceBackups}) and the export jobs
 * ({@see ExportServerDatabaseBackupJob}, {@see ExportSiteFileBackupJob}).
 *
 * Mirrors {@see ServerSnapshotNotificationDispatcher}. Subject is the {@see Server};
 * the per-kind title is pulled from the config label, and the backup type travels
 * in the resource label + metadata.
 */
final class ServerBackupNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $resourceLabels  affected backup/schedule labels
     * @param  array<string, mixed> $extraMetadata  should include `backup_type`
     */
    public function notify(
        Server $server,
        string $kind,
        array $resourceLabels = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerBackupNotificationKeys::KINDS, true)) {
            return;
        }

        $resourceLabels = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $resourceLabels,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerBackupNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        // The operator who triggered a finished run (success/failure) always gets
        // the in-app bell entry, regardless of subscription / stakeholder status.
        $alwaysNotify = $actor !== null && in_array($kind, ['completed', 'failed'], true);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        if ($resourceLabels !== []) {
            $count = count($resourceLabels);
            $lines[] = $count === 1
                ? __('Backup: :name', ['name' => $resourceLabels[0]])
                : __('Backups (:count): :names', ['count' => $count, 'names' => implode(', ', $resourceLabels)]);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.backups', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'backup_labels' => $resourceLabels,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
            additionalRecipientUsers: $alwaysNotify ? [$actor] : [],
        );

        // Transient, app-wide toast for the triggering operator on a finished run
        // (success or failure) — even while they're on another page.
        if ($actor !== null && in_array($kind, ['completed', 'failed'], true) && filled($server->organization_id)) {
            $resourceLabel = $resourceLabels[0] ?? $label;
            $message = $kind === 'completed'
                ? __(':server — :label complete', ['server' => $server->name, 'label' => $resourceLabel])
                : __(':server — :label failed', ['server' => $server->name, 'label' => $resourceLabel]);

            BackupStatusBroadcast::dispatch(
                (string) $server->organization_id,
                (string) $actor->id,
                $message,
                $kind === 'completed' ? 'success' : 'error',
                (string) $server->id,
            );
        }
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.server_backup.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
