<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Services;

use App\Modules\Edge\Services\EdgeCloudflareClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Cloudflare Workers KV client for the realtime resource. dply
 * provisions a realtime app by writing its credential record into the APPS
 * KV namespace the realtime Worker reads from; it never re-deploys the Worker.
 *
 * Mirrors {@see EdgeCloudflareClient} but scoped to the KV
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
     * Confirm the API token is valid and active (GET /user/tokens/verify).
     */
    public function tokenIsActive(): bool
    {
        if ($this->apiToken === '') {
            return false;
        }

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->get(self::BASE.'/user/tokens/verify');

        return $response->successful() && (string) $response->json('result.status') === 'active';
    }

    /**
     * All KV namespaces in the account, keyed by title → id.
     *
     * @return array<string, string>
     */
    /** @return array<string, mixed> */
    public function listNamespaces(): array
    {
        $this->assertAccountConfigured();

        $namespaces = [];
        $page = 1;

        do {
            $response = Http::withToken($this->apiToken)
                ->acceptJson()
                ->get(self::BASE.'/accounts/'.$this->accountId.'/storage/kv/namespaces', [
                    'per_page' => 100,
                    'page' => $page,
                ]);
            $this->assertSuccess($response, 'list KV namespaces');

            foreach ((array) $response->json('result', []) as $namespace) {
                if (isset($namespace['id'], $namespace['title'])) {
                    $namespaces[(string) $namespace['title']] = (string) $namespace['id'];
                }
            }

            $totalPages = (int) ($response->json('result_info.total_pages') ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $namespaces;
    }

    /**
     * Create a KV namespace and return its id.
     */
    public function createNamespace(string $title): string
    {
        $this->assertAccountConfigured();

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post(self::BASE.'/accounts/'.$this->accountId.'/storage/kv/namespaces', [
                'title' => $title,
            ]);
        $this->assertSuccess($response, 'create KV namespace '.$title);

        $id = (string) $response->json('result.id');
        if ($id === '') {
            throw new RuntimeException('Cloudflare did not return a namespace id for '.$title.'.');
        }

        return $id;
    }

    /**
     * Whether the given namespace id exists in the account.
     */
    public function namespaceExists(string $namespaceId): bool
    {
        if ($namespaceId === '') {
            return false;
        }

        return in_array($namespaceId, array_values($this->listNamespaces()), true);
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

    private function assertAccountConfigured(): void
    {
        if ($this->accountId === '' || $this->apiToken === '') {
            throw new RuntimeException(
                'Realtime Cloudflare client is not configured — set DPLY_REALTIME_CF_ACCOUNT_ID '
                .'(or DPLY_EDGE_CF_ACCOUNT_ID) and DPLY_REALTIME_CF_API_TOKEN (or DPLY_EDGE_CF_API_TOKEN).'
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
