<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Neon (serverless Postgres) API.
 *
 * Neon is a BYO-account vendor: the org connects a Neon API key (stored as a
 * ProviderCredential with provider `neon`) and we create a project on their
 * account. A created project returns its connection immediately, but the
 * backing endpoint operations finish a few seconds later — callers poll
 * {@see listOperations()} until none are pending before treating it as ready.
 *
 * ⚠️ Needs live validation: response field paths (connection_uris →
 * connection_parameters) follow Neon's published shape but were not verified
 * against a live account.
 */
class NeonService
{
    protected string $baseUrl = 'https://console.neon.tech/api/v2';

    protected string $token;

    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('Neon API token is required.');
        }
        $this->token = $token;
    }

    /**
     * Create a Neon project and return its id + connection block. The
     * connection is usable once {@see listOperations()} reports no pending
     * operations.
     *
     * @return array{id: string, connection: array<string, mixed>, pending: bool}
     */
    public function createProject(string $name, string $regionId, int $pgVersion = 16): array
    {
        $response = $this->request('post', '/projects', [
            'project' => [
                'name' => $name,
                'region_id' => $regionId,
                'pg_version' => $pgVersion,
            ],
        ]);
        $this->assertSuccess($response, 'create project');

        $project = (array) $response->json('project');
        $uris = $response->json('connection_uris');
        $params = is_array($uris) && isset($uris[0]['connection_parameters'])
            ? (array) $uris[0]['connection_parameters']
            : [];

        return [
            'id' => (string) ($project['id'] ?? ''),
            'connection' => $params === [] ? [] : [
                'host' => (string) ($params['host'] ?? ''),
                'port' => '5432',
                'username' => (string) ($params['role'] ?? ''),
                'password' => (string) ($params['password'] ?? ''),
                'database' => (string) ($params['database'] ?? ''),
                'ssl' => true,
            ],
            'pending' => $this->operationsPending($response->json('operations')),
        ];
    }

    /**
     * Whether the project still has running/scheduling operations.
     */
    public function hasPendingOperations(string $projectId): bool
    {
        $response = $this->request('get', '/projects/'.$projectId.'/operations');
        $this->assertSuccess($response, 'list project operations');

        return $this->operationsPending($response->json('operations'));
    }

    public function deleteProject(string $projectId): bool
    {
        $response = $this->request('delete', '/projects/'.$projectId);
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccess($response, 'delete project');

        return true;
    }

    private function operationsPending(mixed $operations): bool
    {
        if (! is_array($operations)) {
            return false;
        }

        foreach ($operations as $op) {
            $status = is_array($op) ? (string) ($op['status'] ?? '') : '';
            if ($status !== '' && ! in_array($status, ['finished', 'skipped', 'cancelled'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json')
            ->connectTimeout(5)
            ->timeout(15);

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

        $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

        throw new \RuntimeException("Neon API failed to {$action}: {$message}");
    }
}
