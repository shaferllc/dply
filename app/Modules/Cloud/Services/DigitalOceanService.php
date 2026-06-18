<?php

namespace App\Modules\Cloud\Services;

use App\Models\ProviderCredential;
use App\Services\Concerns\ManagesDoCatalog;
use App\Services\Concerns\ManagesDoDomainsSshKeys;
use App\Services\Concerns\ManagesDoDroplets;
use App\Services\Concerns\ManagesDoFunctionsDatabases;
use App\Services\Concerns\ManagesDoKubernetes;
use App\Services\Concerns\ManagesDoSpacesRegistry;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
    use ManagesDoCatalog;
    use ManagesDoDomainsSshKeys;
    use ManagesDoDroplets;
    use ManagesDoFunctionsDatabases;
    use ManagesDoKubernetes;
    use ManagesDoSpacesRegistry;

    protected string $baseUrl = 'https://api.digitalocean.com/v2';

    protected string $token;

    /**
     * @param  ProviderCredential|non-empty-string  $credentialOrToken  Saved credential or a raw API token string.
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('DigitalOcean API token is required.');
        }
        $this->token = $token;
    }


    /**
     * @param  array<string, mixed> $bodyOrQuery
     */
    protected function request(string $method, string $path, array $bodyOrQuery = []): Response
    {
        $url = $this->baseUrl.$path;
        // Bounded timeouts: connect within 5s, finish within 8s. Without these,
        // a slow or stuck DO endpoint can consume the request's entire 30s
        // PHP max-execution-time budget — the server-create wizard hits the
        // catalog (regions/sizes) on every render, so any stall blocks the
        // whole page. The catalog calls also cache for 10 minutes upstream,
        // so this timeout only applies to fresh fetches.
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json')
            ->connectTimeout(5)
            ->timeout(8);

        $method = strtolower($method);
        if ($method === 'get') {
            return $request->get($url, $bodyOrQuery);
        }
        if ($method === 'post') {
            return $request->post($url, $bodyOrQuery);
        }
        if ($method === 'delete') {
            return $request->delete($url);
        }

        throw new \InvalidArgumentException("Unsupported method: {$method}");
    }


    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

        throw new \RuntimeException("DigitalOcean API failed to {$action}: {$message}");
    }
}
