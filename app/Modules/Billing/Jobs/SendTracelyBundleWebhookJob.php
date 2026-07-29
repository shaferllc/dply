<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Enums\BundleTransition;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Delivers a `bundle.*` transition to tracely's inbound provisioning webhook.
 *
 * Signed (HMAC-SHA256 over the raw body, timestamped for replay protection) and
 * idempotent + ordered via a single monotonic ULID `id` (dedupe on it, apply
 * only if greater than the last-applied id for that org) so tracely can drop
 * retries and reject stale/out-of-order events. Lands DARK when the
 * URL/secret aren't configured — the perk's fast path is best-effort; the
 * nightly reconcile + entitlements API is the correctness backstop, so a dropped
 * webhook self-heals rather than corrupting state.
 *
 * See docs/adr/bundled-products-sso.md.
 */
final class SendTracelyBundleWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $organizationId,
        public readonly BundleTransition $transition,
        public readonly string $eventId,
    ) {}

    /** Exponential backoff between retries (seconds). */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(): void
    {
        $url = (string) config('bundle.tracely.webhook_url', '');
        $secret = (string) config('bundle.tracely.webhook_secret', '');

        // Dark until tracely's endpoint + secret exist. Reconcile is the backstop.
        if (! config('bundle.enabled', false) || $url === '' || $secret === '') {
            return;
        }

        $organization = Organization::query()->find($this->organizationId);
        if ($organization === null) {
            return;
        }

        $payload = [
            'id' => $this->eventId,
            'type' => $this->transition->value,
            'occurred_at' => now()->toIso8601String(),
            'org' => [
                'id' => (string) $organization->id,
                'name' => (string) $organization->name,
            ],
            'entitlement' => [
                'plan' => $organization->planTierLabel(),
                'qualifies' => $organization->qualifiesForBundledProducts(),
            ],
        ];

        // Sign the EXACT bytes tracely will verify — encode once, hash that.
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Dply-Signature' => 't='.$timestamp.',v1='.$signature,
            'X-Dply-Event' => $this->eventId,
        ])
            ->timeout(15)
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }

    public static function idFor(): string
    {
        return (string) Str::ulid();
    }
}
