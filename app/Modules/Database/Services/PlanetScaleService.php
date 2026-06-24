<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for the PlanetScale (serverless MySQL/Vitess) API.
 *
 * Auth uses a service token in the form `id:token`, passed verbatim in the
 * Authorization header (NOT a Bearer). Databases are org-scoped; a connection
 * is obtained by creating a password on a branch (default `main`), which
 * returns the host/username and the one-time plaintext password.
 *
 * ⚠️ Needs live validation: endpoint paths + response field names follow
 * PlanetScale's published API but were not verified against a live account.
 */
class PlanetScaleService
{
    protected string $baseUrl = 'https://api.planetscale.com/v1';

    protected string $token;

    protected string $org;

    public function __construct(ProviderCredential $credential)
    {
        $creds = $credential->credentials ?? [];
        $this->token = trim((string) ($creds['api_token'] ?? ''));
        if ($this->token === '') {
            throw new \InvalidArgumentException('PlanetScale service token is required (format id:token).');
        }
        $this->org = trim((string) ($creds['account'] ?? ''));
    }

    /** The org slug to scope calls under — the configured one or the first the token can see. */
    public function organization(): string
    {
        if ($this->org !== '') {
            return $this->org;
        }

        $response = $this->request('get', '/organizations');
        $this->assertSuccess($response, 'list organizations');
        $first = $response->json('data.0.name') ?? $response->json('data.0.id');
        $this->org = (string) ($first ?? '');
        if ($this->org === '') {
            throw new \RuntimeException('No PlanetScale organization is accessible with this token.');
        }

        return $this->org;
    }

    public function createDatabase(string $name, string $region): string
    {
        $org = $this->organization();
        $response = $this->request('post', "/organizations/{$org}/databases", [
            'name' => $name,
            'region' => $region,
        ]);
        $this->assertSuccess($response, 'create database');

        return (string) ($response->json('name') ?? $name);
    }

    /** Current database state — `ready` once provisioned. */
    public function databaseState(string $name): string
    {
        $org = $this->organization();
        $response = $this->request('get', "/organizations/{$org}/databases/{$name}");
        $this->assertSuccess($response, 'get database');

        return (string) ($response->json('state') ?? '');
    }

    /**
     * Create a password on a branch and return the connection block (the
     * plaintext password is only returned at creation time).
     *
     * @return array{host: string, username: string, password: string}
     */
    public function createBranchPassword(string $database, string $branch = 'main'): array
    {
        $org = $this->organization();
        $response = $this->request('post', "/organizations/{$org}/databases/{$database}/branches/{$branch}/passwords", [
            'name' => 'dply',
        ]);
        $this->assertSuccess($response, 'create branch password');

        return [
            'host' => (string) ($response->json('access_host_url') ?? $response->json('hostname') ?? ''),
            'username' => (string) ($response->json('username') ?? ''),
            'password' => (string) ($response->json('plain_text') ?? ''),
        ];
    }

    protected function request(string $method, string $path, array $body = []): Response
    {
        $request = Http::withHeaders(['Authorization' => $this->token])
            ->acceptJson()
            ->contentType('application/json')
            ->connectTimeout(5)
            ->timeout(15);

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
        $message = $response->json('message') ?? $response->body() ?: $response->reason();
        throw new \RuntimeException("PlanetScale API failed to {$action}: {$message}");
    }
}
