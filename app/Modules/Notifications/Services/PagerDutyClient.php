<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the PagerDuty Events API v2 for PagerDuty notification
 * channels.
 *
 * PagerDuty's failure shape is the most straightforward of the providers here:
 * honest status codes, 202 on success, and a 400 body carrying a plain
 * `errors` array of human-readable strings — no machine codes to switch on, so
 * describeError() keys off the status and matches on the message text for the
 * two cases an operator can actually fix.
 *
 * Credentials are per-channel: a routing key is an *integration* key belonging
 * to one PagerDuty service, so "which service does this alert page" is exactly
 * the choice an operator makes when creating the channel.
 */
class PagerDutyClient
{
    /**
     * PagerDuty serves EU accounts from a separate host, and a key from one
     * region is rejected by the other.
     */
    private const BASE_URLS = [
        'us' => 'https://events.pagerduty.com',
        'eu' => 'https://events.eu.pagerduty.com',
    ];

    public function __construct(
        private readonly string $region = 'us',
    ) {}

    public static function make(?string $region = null): self
    {
        if ($region === null || $region === '') {
            $configured = config('services.pagerduty.region');
            $region = is_string($configured) && $configured !== '' ? $configured : 'us';
        }

        return new self(self::normalizeRegion($region));
    }

    /**
     * Whether the deployment has an app-level routing key. Mirrors
     * IntercomClient::appTokenConfigured().
     */
    public static function appRoutingKeyConfigured(): bool
    {
        $key = config('services.pagerduty.routing_key');

        return is_string($key) && $key !== '';
    }

    public static function appRoutingKey(): string
    {
        $key = config('services.pagerduty.routing_key');

        return is_string($key) ? $key : '';
    }

    public static function normalizeRegion(string $region): string
    {
        $region = mb_strtolower(trim($region));

        return isset(self::BASE_URLS[$region]) ? $region : 'us';
    }

    /**
     * @return list<string>
     */
    public static function regions(): array
    {
        return array_keys(self::BASE_URLS);
    }

    public static function labelForRegion(string $region): string
    {
        return match (self::normalizeRegion($region)) {
            'eu' => __('Europe (events.eu.pagerduty.com)'),
            default => __('United States (events.pagerduty.com)'),
        };
    }

    /**
     * Enqueue an alert event.
     *
     * @param  array<string, mixed>  $payload  Built by PagerDutyMessage::toArray()
     * @return array{ok: bool, error: string, status: int, dedup_key: string}
     */
    public function enqueue(array $payload): array
    {
        $routingKey = $payload['routing_key'] ?? null;
        if (! is_string($routingKey) || $routingKey === '') {
            return ['ok' => false, 'error' => 'not_configured', 'status' => 0, 'dedup_key' => ''];
        }

        $url = (self::BASE_URLS[$this->region] ?? self::BASE_URLS['us']).'/v2/enqueue';

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json'])
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 0, 'dedup_key' => ''];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => self::extractError($body, $response->status()),
                'status' => $response->status(),
                'dedup_key' => '',
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'status' => $response->status(),
            // PagerDuty echoes the dedup key it used — the caller needs it to
            // resolve this incident later.
            'dedup_key' => (string) ($body['dedup_key'] ?? ''),
        ];
    }

    /**
     * Turn a normalised error into copy an operator can act on.
     *
     * PagerDuty has no error-code vocabulary, so this keys off the status and
     * the message text. The two that matter are a bad routing key (which comes
     * back as a 400 mentioning the routing key, NOT a 401 — a real trap when
     * debugging) and the rate limit.
     */
    public static function describeError(string $error): string
    {
        $lower = mb_strtolower($error);

        return match (true) {
            $error === '' => __('PagerDuty rejected the request.'),
            $error === 'not_configured' => __('No PagerDuty integration key is set for this channel.'),
            $error === 'http_429' => __('PagerDuty is rate limiting us. Try again shortly.'),
            $error === 'http_403' => __('PagerDuty refused the request. Check the integration key has not been disabled on the service.'),
            // A wrong or wrong-region key produces a 400 naming routing_key,
            // not a 401 — worth saying out loud, because the status suggests a
            // malformed payload rather than a credential problem.
            str_contains($lower, 'routing_key') || str_contains($lower, 'routing key') => __('PagerDuty did not recognise the integration key. Check it was copied in full, and that it matches the selected region.'),
            str_contains($lower, 'dedup') => __('PagerDuty rejected the deduplication key. Resolving an incident requires the key from the original alert.'),
            str_contains($lower, 'severity') => __('PagerDuty rejected the severity. It must be critical, error, warning, or info.'),
            str_contains($lower, 'expired') => __('That PagerDuty event was rejected as expired. Check this server\'s clock.'),
            default => __('PagerDuty returned :error.', ['error' => $error]),
        };
    }

    /**
     * A 400 body looks like {"status":"invalid event","errors":["..."]} — the
     * strings are already human-readable, so join rather than map.
     *
     * @param  array<string, mixed>  $body
     */
    private static function extractError(array $body, int $status): string
    {
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $flat = array_filter(array_map(
                static fn ($e): string => is_string($e) ? $e : '',
                $errors
            ));

            if ($flat !== []) {
                return implode('; ', $flat);
            }
        }

        $message = $body['message'] ?? $body['status'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'http_'.$status;
    }
}
