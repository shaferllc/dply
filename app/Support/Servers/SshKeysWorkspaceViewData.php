<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceSshKeys;
use App\Models\Server;
use App\Models\ServerSshKeyAuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * View-model for the server SSH keys workspace blade tree. Keeps banner/setup
 * and tab preamble logic out of {@see resources/views/livewire/servers/workspace-ssh-keys.blade.php}.
 */
final class SshKeysWorkspaceViewData
{
    /**
     * @param  Collection<int, ServerSshKeyAuditEvent>|null  $auditEvents
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        WorkspaceSshKeys $component,
        bool $includeKeysContext = false,
        bool $includePreviewContext = false,
        bool $includeActivityContext = false,
        ?Collection $auditEvents = null,
    ): array {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->hasAnySshPrivateKey();

        $syncStatus = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
        $syncRunId = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_run_id_key'));
        $syncError = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_error_key'));
        $syncStartedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_started_at_key'));
        $syncFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_finished_at_key'));
        $syncBusy = in_array($syncStatus, ['queued', 'running'], true);
        $syncShowBanner = $syncRunId !== '' && in_array($syncStatus, ['queued', 'running', 'completed', 'failed'], true);

        $driftStatus = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_status_key'));
        $driftRunId = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_run_id_key'));
        $driftError = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_error_key'));
        $driftStartedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_started_at_key'));
        $driftFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_finished_at_key'));
        $driftBusy = in_array($driftStatus, ['queued', 'running'], true);
        $driftShowBanner = $driftRunId !== '' && in_array($driftStatus, ['queued', 'running', 'completed', 'failed'], true);
        $driftHasChanges = (bool) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_has_changes_key'));
        $driftAddedCount = (int) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_added_count_key'));
        $driftRemovedCount = (int) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_removed_count_key'));

        $panelShowBanner = ! empty($component->panel_event_lines);

        if ($syncBusy) {
            $bannerKind = 'sync';
        } elseif ($driftBusy) {
            $bannerKind = 'drift';
        } elseif ($syncShowBanner && $driftShowBanner) {
            $bannerKind = (string) ($syncStartedAt ?? '') >= (string) ($driftStartedAt ?? '') ? 'sync' : 'drift';
        } elseif ($syncShowBanner) {
            $bannerKind = 'sync';
        } elseif ($driftShowBanner) {
            $bannerKind = 'drift';
        } elseif ($panelShowBanner) {
            $bannerKind = 'panel';
        } else {
            $bannerKind = null;
        }

        $bannerBusy = ($bannerKind === 'sync' && $syncBusy) || ($bannerKind === 'drift' && $driftBusy);
        $bannerOutput = match ($bannerKind) {
            'sync' => $syncShowBanner ? $component->getSyncOutputLinesProperty() : [],
            'drift' => $component->diff_output,
            'panel' => $component->panel_event_lines,
            default => [],
        };
        $bannerStatus = match ($bannerKind) {
            'sync' => $syncStatus,
            'drift' => $driftStatus,
            'panel' => $component->panel_event_status,
            default => '',
        };
        $bannerMessage = match ($bannerKind) {
            'sync' => match ($syncStatus) {
                'queued' => __('Sync queued — waiting for a worker to pick it up…'),
                'running' => __('Syncing authorized_keys to :host …', ['host' => $server->getSshConnectionString()]),
                'completed' => __('Sync complete — authorized_keys updated.'),
                'failed' => __('Sync failed — authorized_keys was not fully updated.'),
                default => '',
            },
            'drift' => match ($driftStatus) {
                'queued' => __('Drift preview queued — waiting for a worker to pick it up…'),
                'running' => __('Comparing authorized_keys against :host …', ['host' => $server->getSshConnectionString()]),
                'completed' => $driftHasChanges
                    ? __('Drift detected — :add to add, :remove to remove.', ['add' => $driftAddedCount, 'remove' => $driftRemovedCount])
                    : __('No drift — the server already matches the panel.'),
                'failed' => __('Drift preview failed.'),
                default => '',
            },
            'panel' => $component->panel_event_message,
            default => '',
        };
        $bannerDismissAction = match ($bannerKind) {
            'drift' => 'dismissDriftBanner',
            'panel' => 'dismissPanelBanner',
            default => 'dismissSyncBanner',
        };
        $bannerSubtitle = $bannerBusy
            ? __('Refreshing every 4s · safe to leave this page — the job runs on the queue.')
            : match (true) {
                $bannerKind === 'sync' && $syncStatus === 'failed' && $syncError !== '' => $syncError,
                $bannerKind === 'sync' && $syncStatus === 'completed' && $syncFinishedAt => __('Finished :time', ['time' => Carbon::parse($syncFinishedAt)->diffForHumans()]),
                $bannerKind === 'drift' && $driftStatus === 'failed' && $driftError !== '' => $driftError,
                $bannerKind === 'drift' && $driftStatus === 'completed' => $driftHasChanges
                    ? __('Compared the panel’s desired keys against the server. See the Drift tab for the structured diff.')
                    : __('authorized_keys on the server already matches your desired keys — nothing to sync.'),
                $bannerKind === 'panel' => __('The panel was updated. The server\'s authorized_keys file is unchanged until you Sync.'),
                default => null,
            };
        $bannerDefaultExpanded = $bannerKind === 'drift' || $bannerKind === 'panel';

        $data = compact(
            'card',
            'opsReady',
            'syncStatus',
            'syncBusy',
            'syncShowBanner',
            'driftStatus',
            'driftBusy',
            'driftShowBanner',
            'driftHasChanges',
            'bannerKind',
            'bannerBusy',
            'bannerOutput',
            'bannerStatus',
            'bannerMessage',
            'bannerDismissAction',
            'bannerSubtitle',
            'bannerDefaultExpanded',
        );

        if ($includeKeysContext) {
            $trackedKeyCount = $server->authorizedKeys->count();
            $lastSyncFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_finished_at_key'));
            $lastSyncStatus = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));

            $data = array_merge($data, compact(
                'trackedKeyCount',
                'lastSyncFinishedAt',
                'lastSyncStatus',
            ));
        }

        if ($includePreviewContext) {
            $lastSyncFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_finished_at_key'));
            $recentlySynced = $lastSyncFinishedAt
                && (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_status_key')) === 'completed';

            $data = array_merge($data, compact(
                'recentlySynced',
            ));
        }

        if ($includeActivityContext && $auditEvents !== null) {
            $activityCount = $auditEvents->count();
            $latestActivity = $auditEvents->first()?->created_at;

            $data = array_merge($data, compact(
                'activityCount',
                'latestActivity',
            ));
        }

        return $data;
    }
}
