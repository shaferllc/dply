<?php

declare(strict_types=1);

namespace App\Services\Imports\Forge;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP transport for Laravel Forge's REST API. Same shape as
 * PloiClient: bearer token, verbose assertSuccess, no business logic.
 * ForgeImportDriver layers normalisation on top.
 */
class ForgeClient
{
    protected string $baseUrl = 'https://forge.laravel.com/api/v1';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (! is_string($token) || $token === '') {
            throw new \InvalidArgumentException('Forge API token is required.');
        }
        $this->token = $token;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->request('get', $path, [], $query);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $path, array $body = []): Response
    {
        return $this->request('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function put(string $path, array $body = []): Response
    {
        return $this->request('put', $path, $body);
    }

    public function delete(string $path): Response
    {
        return $this->request('delete', $path);
    }

    public function assertSuccess(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        $status = $response->status();
        $body = (string) $response->body();
        throw new RuntimeException(sprintf(
            'Forge API call failed (%s): HTTP %d — %s',
            $context,
            $status,
            $body !== '' ? mb_substr($body, 0, 500) : '(empty body)',
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    protected function request(string $method, string $path, array $body = [], array $query = []): Response
    {
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $req = Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            // Same retry posture as PloiClient — exponential backoff + Retry-After.
            ->retry(3, function (int $attempt, \Throwable $exception): int {
                $delay = (int) (1000 * (2 ** ($attempt - 1)));
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $retryAfter = $exception->response?->header('Retry-After');
                    if (is_string($retryAfter) && ctype_digit($retryAfter)) {
                        $delay = max($delay, (int) $retryAfter * 1000);
                    }
                }

                return $delay;
            }, function (\Throwable $exception): bool {
                if (! $exception instanceof \Illuminate\Http\Client\RequestException) {
                    return false;
                }
                $status = $exception->response?->status();

                return in_array($status, [429, 502, 503, 504], true);
            }, throw: false);

        return match (strtolower($method)) {
            'get' => $req->get($url, $query),
            'post' => $req->post($url, $body),
            'put' => $req->put($url, $body),
            'delete' => $req->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
