<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for the Supabase (serverless Postgres) Management API.
 *
 * Auth is a Bearer personal access token. Projects are org-scoped and
 * provision asynchronously (status COMING_UP → ACTIVE_HEALTHY). The DB password
 * is one we supply at create time; the connection host is derived from the
 * project ref (db.<ref>.supabase.co).
 *
 * ⚠️ Needs live validation against a real account.
 */
class SupabaseService
{
    protected string $baseUrl = 'https://api.supabase.com/v1';

    protected string $token;

    protected string $org;

    public function __construct(ProviderCredential $credential)
    {
        $creds = $credential->credentials ?? [];
        $this->token = trim((string) ($creds['api_token'] ?? ''));
        if ($this->token === '') {
            throw new \InvalidArgumentException('Supabase access token is required.');
        }
        $this->org = trim((string) ($creds['account'] ?? ''));
    }

    public function organization(): string
    {
        if ($this->org !== '') {
            return $this->org;
        }

        $response = $this->request('get', '/organizations');
        $this->assertSuccess($response, 'list organizations');
        $this->org = (string) ($response->json('0.id') ?? '');
        if ($this->org === '') {
            throw new \RuntimeException('No Supabase organization is accessible with this token.');
        }

        return $this->org;
    }

    /**
     * Create a project; returns its ref (id). Status is polled separately.
     */
    public function createProject(string $name, string $dbPass, string $region): string
    {
        $response = $this->request('post', '/projects', [
            'organization_id' => $this->organization(),
            'name' => $name,
            'db_pass' => $dbPass,
            'region' => $region,
        ]);
        $this->assertSuccess($response, 'create project');

        return (string) ($response->json('id') ?? $response->json('ref') ?? '');
    }

    public function projectStatus(string $ref): string
    {
        $response = $this->request('get', '/projects/'.$ref);
        $this->assertSuccess($response, 'get project');

        return (string) ($response->json('status') ?? '');
    }

    protected function request(string $method, string $path, array $body = []): Response
    {
        $request = Http::withToken($this->token)
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
        $message = $response->json('message') ?? $response->body() ?: $response->reason();
        throw new \RuntimeException("Supabase API failed to {$action}: {$message}");
    }
}
