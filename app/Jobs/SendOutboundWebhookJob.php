<?php

namespace App\Jobs;

use App\Models\OutboundWebhookDelivery;
use App\Models\Server;
use App\Services\Webhooks\OutboundWebhookSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Performs the HTTP POST for an outbound webhook delivery. Retries with backoff up to 3
 * times before marking the delivery failed. Updates the delivery row in place so the UI
 * can show progress (attempt count, last status, response excerpt).
 */
class SendOutboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Backoff between attempts (seconds). */
    public function backoff(): array
    {
        return [10, 30, 120];
    }

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        /** @var OutboundWebhookDelivery|null $delivery */
        $delivery = OutboundWebhookDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->url === null || $delivery->url === '') {
            return;
        }
        if ($delivery->status === OutboundWebhookDelivery::STATUS_SENT) {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $delivery->update([
                'status' => OutboundWebhookDelivery::STATUS_FAILED,
                'error_message' => 'Could not encode payload as JSON.',
                'completed_at' => now(),
            ]);

            return;
        }

        $headers = ['Content-Type' => 'application/json'];
        $secret = $this->resolveSecret($delivery);
        if ($secret !== null) {
            $ts = time();
            $headers['X-Dply-Timestamp'] = (string) $ts;
            $headers['X-Dply-Signature'] = OutboundWebhookSignature::header($secret, $ts, $body);
        }
        $headers['X-Dply-Event'] = $delivery->event_key;
        $headers['X-Dply-Delivery-Id'] = $delivery->id;
        $headers['User-Agent'] = 'Dply-Outbound-Webhook/1';

        $delivery->forceFill([
            'attempt_count' => $delivery->attempt_count + 1,
            'first_attempt_at' => $delivery->first_attempt_at ?? now(),
        ])->save();

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($delivery->url);

            $excerpt = Str::limit((string) $response->body(), 4000, '');
            $delivery->forceFill([
                'http_status' => $response->status(),
                'response_excerpt' => $excerpt !== '' ? $excerpt : null,
            ])->save();

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => OutboundWebhookDelivery::STATUS_SENT,
                    'error_message' => null,
                    'completed_at' => now(),
                ])->save();

                return;
            }

            $delivery->forceFill([
                'error_message' => 'Endpoint returned HTTP '.$response->status().'.',
            ])->save();
            $this->failOrRetry($delivery, 'http_'.$response->status());

            return;
        } catch (\Throwable $e) {
            $delivery->forceFill([
                'error_message' => Str::limit($e->getMessage(), 500),
            ])->save();
            $this->failOrRetry($delivery, 'transport');
        }
    }

    public function failed(\Throwable $e): void
    {
        $delivery = OutboundWebhookDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }
        $delivery->forceFill([
            'status' => OutboundWebhookDelivery::STATUS_FAILED,
            'error_message' => $delivery->error_message ?: Str::limit($e->getMessage(), 500),
            'completed_at' => now(),
        ])->save();
    }

    private function failOrRetry(OutboundWebhookDelivery $delivery, string $reason): void
    {
        if ($delivery->attempt_count >= $this->tries) {
            $delivery->forceFill([
                'status' => OutboundWebhookDelivery::STATUS_FAILED,
                'completed_at' => now(),
            ])->save();

            return;
        }

        $this->release($this->backoff()[$delivery->attempt_count - 1] ?? 60);
    }

    private function resolveSecret(OutboundWebhookDelivery $delivery): ?string
    {
        $server = $delivery->server_id !== null ? Server::query()->find($delivery->server_id) : null;
        if ($server === null) {
            return null;
        }
        $meta = $server->meta ?? [];
        $enc = $meta['server_event_webhook_secret'] ?? null;
        if (! is_string($enc) || $enc === '') {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }
}
