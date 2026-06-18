<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed over the dply realtime relay when a quick download finishes (ready or
 * failed) so the requester gets a transient, app-wide toast — even after they've
 * navigated off the backups page (the in-app bell + email are the durable record;
 * this is the "it's done" nudge). Mirrors {@see BackupStatusBroadcast}: broadcast
 * on the org channel (already subscribed app-wide in bootstrap.js) and filtered to
 * the triggering user via {@see $userId} so other admins aren't spammed.
 */
final class QuickDownloadStatusBroadcast implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
        public readonly string $message,
        public readonly string $type,
        public readonly ?string $quickDownloadId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organization.'.$this->organizationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'quick-download.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'message' => $this->message,
            'type' => $this->type,
            'quick_download_id' => $this->quickDownloadId,
        ];
    }
}
