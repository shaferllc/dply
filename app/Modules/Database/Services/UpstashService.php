<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for the Upstash (serverless Redis) management API.
 *
 * Auth is HTTP Basic: the account email as the username, the API key as the
 * password. A created database returns its endpoint/port/password immediately
 * (no async wait).
 *
 * ⚠️ Needs live validation against a real account.
 */
class UpstashService
{
    protected string $baseUrl = 'https://api.upstash.com/v2';

    protected string $email;

    protected string $apiKey;

    public function __construct(ProviderCredential $credential)
    {
        $creds = $credential->credentials ?? [];
        $this->apiKey = trim((string) ($creds['api_token'] ?? ''));
        $this->email = trim((string) ($creds['account'] ?? ''));
        if ($this->apiKey === '' || $this->email === '') {
            throw new \InvalidArgumentException('Upstash requires both an account email and an API key.');
        }
    }

    /**
     * Create a Redis database and return its connection block.
     *
     * @return array{host: string, port: string, password: string}
     */
    public function createDatabase(string $name, string $region): array
    {
        $response = $this->request('post', '/redis/database', [
            'name' => $name,
            'region' => $region,
            'tls' => true,
        ]);
        $this->assertSuccess($response, 'create database');

        return [
            'host' => (string) ($response->json('endpoint') ?? ''),
            'port' => (string) ($response->json('port') ?? '6379'),
            'password' => (string) ($response->json('password') ?? ''),
        ];
    }

    protected function request(string $method, string $path, array $body = []): Response
    {
        $request = Http::withBasicAuth($this->email, $this->apiKey)
            ->acceptJson()
            ->contentType('application/json')
            ->connectTimeout(5)
            ->timeout(20);

        $url = $this->baseUrl.$path;

        return match (strtolower($method)) {
            'get' => $request->get($url),
            'post' => $request->post($url, $body),
            'delete' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }
        $message = $response->json('error') ?? $response->body() ?: $response->reason();
        throw new \RuntimeException("Upstash API failed to {$action}: {$message}");
    }
}
