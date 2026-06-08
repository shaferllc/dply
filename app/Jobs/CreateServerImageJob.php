<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerImage;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        } catch (\Throwable $e) {
            $image->update([
                'status' => ServerImage::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
