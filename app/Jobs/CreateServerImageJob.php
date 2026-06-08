<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerImage;
use App\Notifications\SnapshotStatusNotification;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Queue-side runner for a single server image capture. The provider create-image
 * action can take several minutes, so this never runs in a web request — the
 * Snapshots workspace creates a pending {@see ServerImage} row and dispatches us.
 *
 * Lifecycle: pending → creating (action fired) → completed | failed.
 * Mirrors {@see ExportRedisSnapshotJob}: any exception marks the row failed with
 * its message so the operator sees what went wrong in the Server images history.
 */
class CreateServerImageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public string $serverImageId,
    ) {}

    public function handle(ServerImageProvider $provider): void
    {
        $image = ServerImage::query()->with('server')->find($this->serverImageId);
        if ($image === null) {
            return;
        }

        $server = $image->server;
        if ($server === null) {
            $image->update([
                'status' => ServerImage::STATUS_FAILED,
                'error_message' => 'Server no longer exists.',
            ]);

            return;
        }

        $image->update(['status' => ServerImage::STATUS_CREATING]);

        try {
            $result = $provider->create(
                $server,
                $image->name,
                onTick: fn (string $note) => Log::debug('CreateServerImageJob tick', ['image' => $image->id, 'note' => $note]),
            );

            $image->update([
                'status' => ServerImage::STATUS_COMPLETED,
                'provider_image_id' => $result['provider_image_id'] ?: null,
                'provider_action_id' => $result['provider_action_id'],
                'region' => $result['region'] ?: $server->region,
                'bytes' => $result['bytes'],
            ]);

            if ($org = $server->organization) {
                audit_log($org, $image->user, 'server_image.created', $image, null, [
                    'provider' => $image->provider,
                    'provider_image_id' => $image->provider_image_id,
                ]);
            }

            $this->notify($image, $server, ServerImage::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $image->update([
                'status' => ServerImage::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $this->notify($image, $server, ServerImage::STATUS_FAILED, $e->getMessage());
        }
    }

    /**
     * Email the org's owners/admins about the image capture's outcome. Best-effort:
     * a notification problem must never fail the (already finished) capture.
     */
    private function notify(ServerImage $image, Server $server, string $status, string $error = ''): void
    {
        try {
            $org = $server->organization;
            if ($org === null) {
                return;
            }

            $admins = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new SnapshotStatusNotification(
                kind: 'image',
                status: $status,
                label: (string) $image->name,
                serverName: (string) ($server->name ?? ''),
                url: route('servers.snapshots', $server->id, absolute: true),
                errorMessage: $error,
            ));
        } catch (\Throwable $e) {
            Log::warning('CreateServerImageJob notify failed', ['image' => $image->id, 'error' => $e->getMessage()]);
        }
    }
}
