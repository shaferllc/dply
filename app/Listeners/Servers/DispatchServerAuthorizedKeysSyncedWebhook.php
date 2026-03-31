<?php

namespace App\Listeners\Servers;

use App\Events\Servers\ServerAuthorizedKeysSynced;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchServerAuthorizedKeysSyncedWebhook
{
    public function handle(ServerAuthorizedKeysSynced $event): void
    {
        $server = $event->server->fresh();
        $meta = $server->meta ?? [];
        $url = isset($meta['server_event_webhook_url']) && is_string($meta['server_event_webhook_url'])
            ? trim($meta['server_event_webhook_url'])
            : '';

        if ($url === '') {
            return;
        }

        $secretEnc = $meta['server_event_webhook_secret'] ?? null;
        if (! is_string($secretEnc) || $secretEnc === '') {
            return;
        }

        try {
            $secret = Crypt::decryptString($secretEnc);
        } catch (\Throwable $e) {
            Log::warning('server_event_webhook_secret decrypt failed for SSH sync webhook', [
                'server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $body = [
            'event' => 'server.authorized_keys.synced',
            'server_id' => $server->id,
            'server_name' => $server->name,
            'initiated_by_user_id' => $event->initiatedBy?->id,
            'summary' => $event->summary,
            'data' => $event->payload,
        ];

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ts = time();
        $sig = WebhookSignature::expectedTimestampedHeader($secret, $ts, $json);

        try {
            Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Dply-Timestamp' => (string) $ts,
                    'X-Dply-Signature' => $sig,
                ])
                ->withBody($json, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('server authorized_keys sync webhook failed', [
                'server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
