<?php

declare(strict_types=1);

namespace App\Services\Imports\Ploi;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin transport wrapper for the Ploi REST API. Mirrors the shape of
 * HetznerService and DigitalOceanService — bearer-token auth, verbose error
 * propagation via assertSuccess(), no business logic. The driver
 * (PloiImportDriver) builds on top and normalises responses.
 */
class PloiClient
{
    protected string $baseUrl = 'https://ploi.io/api';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (! is_string($token) || $token === '') {
            throw new \InvalidArgumentException('Ploi API token is required.');
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
            'Ploi API call failed (%s): HTTP %d — %s',
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
            ->asJson();

        return match (strtolower($method)) {
            'get' => $req->get($url, $query),
            'post' => $req->post($url, $body),
            'put' => $req->put($url, $body),
            'delete' => $req->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
