<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Cloudflare Workers KV client for the realtime resource. dply
 * provisions a realtime app by writing its credential record into the APPS
 * KV namespace the realtime Worker reads from; it never re-deploys the Worker.
 *
 * Mirrors {@see \App\Services\Edge\EdgeCloudflareClient} but scoped to the KV
 * value read/write/delete endpoints the realtime backend needs.
 */
class RealtimeCloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('realtime.cloudflare.account_id'),
            (string) config('realtime.cloudflare.api_token'),
        );
    }

    /**
     * Write a string value at $key in the given KV namespace.
     */
    public function putKvValue(string $namespaceId, string $key, string $value): void
    {
        $this->assertConfigured($namespaceId);

        $response = Http::withToken($this->apiToken)
            ->withBody($value, 'text/plain')
            ->put($this->valueUrl($namespaceId, $key));

        $this->assertSuccess($response, 'write KV key '.$key);
    }

    public function deleteKvValue(string $namespaceId, string $key): void
    {
        $this->assertConfigured($namespaceId);

        $response = Http::withToken($this->apiToken)
            ->delete($this->valueUrl($namespaceId, $key));

        // A missing key is already in the desired (absent) state.
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete KV key '.$key);
    }

    private function valueUrl(string $namespaceId, string $key): string
    {
        return self::BASE
            .'/accounts/'.$this->accountId
            .'/storage/kv/namespaces/'.rawurlencode($namespaceId)
            .'/values/'.rawurlencode($key);
    }

    private function assertConfigured(string $namespaceId): void
    {
        if ($this->accountId === '' || $this->apiToken === '' || $namespaceId === '') {
            throw new RuntimeException(
                'Realtime Cloudflare client is not configured — set DPLY_REALTIME_CF_ACCOUNT_ID, '
                .'DPLY_REALTIME_CF_API_TOKEN, and DPLY_REALTIME_CF_KV_NAMESPACE_ID.'
            );
        }
    }

    private function assertSuccess(Response $response, string $action): void
    {
        // KV value PUT/DELETE return an empty 200 body on success rather than
        // the standard {success:true} envelope, so key off the HTTP status.
        if ($response->successful()) {
            return;
        }

        $json = $response->json();
        $message = is_array($json) && isset($json['errors'][0]['message'])
            ? (string) $json['errors'][0]['message']
            : 'HTTP '.$response->status();

        throw new RuntimeException('Cloudflare KV failed to '.$action.': '.$message);
    }
}
