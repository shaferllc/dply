<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Services;

use App\Modules\Realtime\Models\RealtimeApp;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publish an event to a realtime app from the control plane.
 *
 * This is the server side of the relay's Pusher-compatible REST trigger. It
 * exists so the console can prove an app works end to end — the page opens a
 * WebSocket to the same app, this pushes a frame, and the frame comes back —
 * without asking an operator to wire up a client first.
 *
 * Auth uses the relay's header scheme (`X-Dply-Key` / `X-Dply-Secret`) rather
 * than a Pusher REST signature: both are accepted by the Worker, and the header
 * form has no timestamp window to drift out of.
 *
 * The app secret never leaves the control plane; callers hand over a model, not
 * credentials.
 */
final class RealtimePublisher
{
    /** A publish should be quick or not at all — this sits in an HTTP request. */
    private const TIMEOUT_SECONDS = 8;

    /**
     * Push one event to one channel.
     *
     * @param  array<string, mixed>  $data
     * @return array{delivered: int, channels: int}
     *
     * @throws RuntimeException when the relay rejects or never answers.
     */
    public function publish(RealtimeApp $app, string $channel, string $event, array $data): array
    {
        if (! $app->isActive()) {
            throw new RuntimeException(__('The app is not active on the relay yet.'));
        }

        try {
            $response = Http::withHeaders($app->statsAuthHeaders())
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post($app->publishEndpoint(), [
                    'name' => $event,
                    'channel' => $channel,
                    'data' => $data,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(__('Could not reach the relay: :error', ['error' => $e->getMessage()]), 0, $e);
        }

        if ($response->status() === 401) {
            // The credentials in KV have drifted from the row — re-provisioning
            // is the fix, and it is not something a retry will resolve.
            throw new RuntimeException(__('The relay rejected these credentials. The app may need re-provisioning.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('The relay returned :status.', ['status' => $response->status()]));
        }

        return [
            // `delivered` is the count of sockets the frame actually reached, so
            // zero is a meaningful answer, not a failure: it means the publish
            // worked and nobody was listening on that channel.
            'delivered' => (int) $response->json('delivered', 0),
            'channels' => (int) $response->json('channels', 0),
        ];
    }
}
