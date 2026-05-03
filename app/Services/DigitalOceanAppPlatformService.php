<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the DigitalOcean App Platform REST API.
 *
 * Docs: https://docs.digitalocean.com/reference/api/api-reference/#tag/Apps
 *
 * Used by the dply edge layer to provision/redeploy container apps
 * on DO. The api_token credential here is the same shape as the
 * regular DigitalOcean credential — App Platform shares the
 * read+write token scope with droplets/dns/etc.
 *
 * Methods are intentionally minimal — just what the dply edge
 * deploy/redeploy/teardown flow needs. We're not trying to expose
 * the full DO Apps surface.
 */
class DigitalOceanAppPlatformService
{
    protected string $baseUrl = 'https://api.digitalocean.com/v2';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->credentials['api_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new \InvalidArgumentException('DigitalOcean App Platform API token is required.');
        }
        $this->token = $token;
    }

    /**
     * Create a new app from a container image. Minimal "service"
     * spec — single component, public TCP ingress on the configured
     * port, single instance, basic plan. Operators who want richer
     * spec (autoscaling, multiple components, env-from-secret) can
     * patch the app after creation via updateApp().
     *
     * @param  array<string, string>  $envVars
     * @return array{id: string, default_ingress: ?string}
     */
    public function createApp(
        string $appName,
        string $region,
        string $image,
        int $port,
        array $envVars = [],
        array $buildEnvVars = [],
        int $instanceCount = 1,
    ): array {
        $envSpec = [];
        foreach ($envVars as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'RUN_TIME'];
        }
        foreach ($buildEnvVars as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'BUILD_TIME'];
        }

        [, $repository, $tag] = $this->parseImageRef($image);

        $body = [
            'spec' => [
                'name' => $appName,
                'region' => $region,
                'services' => [[
                    'name' => 'web',
                    'image' => [
                        'registry_type' => 'DOCKER_HUB',
                        'repository' => $repository,
                        'tag' => $tag,
                    ],
                    'http_port' => $port,
                    'instance_count' => max(1, $instanceCount),
                    'instance_size_slug' => 'basic-xxs',
                    'envs' => $envSpec,
                ]],
            ],
        ];

        $response = $this->request('post', '/apps', $body);
        $this->assertSuccess($response, 'create app');
        $data = $response->json('app') ?? [];

        return [
            'id' => (string) ($data['id'] ?? ''),
            'default_ingress' => $data['default_ingress'] ?? null,
        ];
    }

    /**
     * Create a new app from a GitHub repo. DO App Platform owns the
     * build (Dockerfile-based when one is present, buildpack
     * auto-detection otherwise) and the auto-redeploy on push when
     * `deploy_on_push` is true. This is the Vercel-style source mode
     * — give it a repo, get back a running URL.
     *
     * `repo` is `owner/name` exactly as DO expects.
     *
     * @param  array<string, string>  $envVars
     * @return array{id: string, default_ingress: ?string}
     */
    public function createAppFromSource(
        string $appName,
        string $region,
        string $repo,
        string $branch,
        int $port,
        bool $deployOnPush = true,
        ?string $dockerfilePath = null,
        array $envVars = [],
        array $buildEnvVars = [],
        int $instanceCount = 1,
    ): array {
        $envSpec = [];
        foreach ($envVars as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'RUN_TIME'];
        }
        foreach ($buildEnvVars as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'BUILD_TIME'];
        }

        $service = [
            'name' => 'web',
            'github' => [
                'repo' => $repo,
                'branch' => $branch,
                'deploy_on_push' => $deployOnPush,
            ],
            'http_port' => $port,
            'instance_count' => max(1, $instanceCount),
            'instance_size_slug' => 'basic-xxs',
            'envs' => $envSpec,
        ];

        if (is_string($dockerfilePath) && $dockerfilePath !== '') {
            $service['dockerfile_path'] = $dockerfilePath;
        }

        $body = [
            'spec' => [
                'name' => $appName,
                'region' => $region,
                'services' => [$service],
            ],
        ];

        $response = $this->request('post', '/apps', $body);
        $this->assertSuccess($response, 'create app from source');
        $data = $response->json('app') ?? [];

        return [
            'id' => (string) ($data['id'] ?? ''),
            'default_ingress' => $data['default_ingress'] ?? null,
        ];
    }

    /**
     * Inspect a single app — used by status polling.
     *
     * @return array<string, mixed>
     */
    public function getApp(string $appId): array
    {
        $response = $this->request('get', '/apps/'.$appId);
        $this->assertSuccess($response, 'get app');

        return $response->json('app') ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApps(): array
    {
        $response = $this->request('get', '/apps');
        $this->assertSuccess($response, 'list apps');
        $apps = $response->json('apps');

        return is_array($apps) ? array_values($apps) : [];
    }

    /**
     * Trigger a redeploy of the existing app. The DO API treats
     * "create deployment" as the right verb for this — the spec
     * stays the same; this just causes the platform to re-pull
     * the latest image tag and roll out a new revision.
     *
     * @return array{id: string}
     */
    public function deployApp(string $appId, bool $force = false): array
    {
        $response = $this->request('post', '/apps/'.$appId.'/deployments', [
            'force_build' => $force,
        ]);
        $this->assertSuccess($response, 'deploy app');
        $deployment = $response->json('deployment') ?? [];

        return [
            'id' => (string) ($deployment['id'] ?? ''),
        ];
    }

    /**
     * Update spec (env vars, image tag, etc.). The DO API requires
     * the entire spec to be passed; callers should fetch first,
     * mutate, then pass through here.
     *
     * @param  array<string, mixed>  $spec
     */
    public function updateApp(string $appId, array $spec): void
    {
        $response = $this->request('put', '/apps/'.$appId, ['spec' => $spec]);
        $this->assertSuccess($response, 'update app');
    }

    public function deleteApp(string $appId): void
    {
        $response = $this->request('delete', '/apps/'.$appId);
        $this->assertSuccess($response, 'delete app');
    }

    /**
     * Attach a custom domain to an app. DO Apps requires the domain
     * to be added to the app's spec (not a side-channel domain
     * resource), so this updates the spec rather than calling a
     * separate /domains endpoint.
     *
     * @param  array<string, mixed>  $existingSpec  Current spec (caller fetches via getApp first)
     */
    public function attachDomain(string $appId, array $existingSpec, string $hostname): void
    {
        $domains = $existingSpec['domains'] ?? [];
        if (! is_array($domains)) {
            $domains = [];
        }
        foreach ($domains as $d) {
            if (is_array($d) && (string) ($d['domain'] ?? '') === $hostname) {
                return;
            }
        }
        $domains[] = [
            'domain' => $hostname,
            'type' => 'PRIMARY',
        ];
        $existingSpec['domains'] = $domains;

        $this->updateApp($appId, $existingSpec);
    }

    /**
     * @param  array<string, mixed>  $existingSpec
     */
    public function detachDomain(string $appId, array $existingSpec, string $hostname): void
    {
        $domains = $existingSpec['domains'] ?? [];
        if (! is_array($domains)) {
            return;
        }
        $filtered = array_values(array_filter($domains, function ($d) use ($hostname): bool {
            return ! is_array($d) || (string) ($d['domain'] ?? '') !== $hostname;
        }));
        if (count($filtered) === count($domains)) {
            return;
        }
        $existingSpec['domains'] = $filtered;
        $this->updateApp($appId, $existingSpec);
    }

    /**
     * Reads the API to confirm the token is valid (cheap call —
     * lists apps with a per-page=1 limit). Throws on auth failure
     * so the caller can surface a credential-rejected error.
     */
    public function validateToken(): void
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->get($this->baseUrl.'/apps?page=1&per_page=1');
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Available DO App Platform regions. Pulled directly from the
     * DO public list — keeping it local avoids a round trip on the
     * region picker and matches how FlyIoService::getRegions does it.
     *
     * @return list<array{slug: string, label: string}>
     */
    public static function getRegions(): array
    {
        return [
            ['slug' => 'ams', 'label' => 'Amsterdam (NL)'],
            ['slug' => 'fra', 'label' => 'Frankfurt (DE)'],
            ['slug' => 'lon', 'label' => 'London (UK)'],
            ['slug' => 'nyc', 'label' => 'New York (NY, US)'],
            ['slug' => 'sfo', 'label' => 'San Francisco (CA, US)'],
            ['slug' => 'sgp', 'label' => 'Singapore (SG)'],
            ['slug' => 'syd', 'label' => 'Sydney (AU)'],
            ['slug' => 'tor', 'label' => 'Toronto (CA)'],
            ['slug' => 'blr', 'label' => 'Bangalore (IN)'],
        ];
    }

    /**
     * Splits a Docker image ref into [registry_host, repository, tag].
     * "ghcr.io/acme/api:v1" → ['ghcr.io', 'acme/api', 'v1']
     * "nginx:1.27"          → ['docker.io', 'library/nginx', '1.27']
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function parseImageRef(string $image): array
    {
        // The tag, if present, is after the last `:` — but only when
        // that colon is *after* the last `/` (otherwise it's a port
        // on the registry host, e.g. "localhost:5000/img").
        $tag = 'latest';
        $lastColon = strrpos($image, ':');
        $lastSlash = strrpos($image, '/');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $tag = substr($image, $lastColon + 1);
            $image = substr($image, 0, $lastColon);
        }

        $parts = explode('/', $image);
        $registry = 'docker.io';
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':'))) {
            $registry = array_shift($parts);
        }
        $repository = implode('/', $parts);
        if ($registry === 'docker.io' && ! str_contains($repository, '/')) {
            $repository = 'library/'.$repository;
        }

        return [$registry, $repository, $tag];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)->acceptJson();
        if ($method === 'get' || $method === 'delete') {
            return $request->$method($url);
        }

        return $request->$method($url, $body);
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'DigitalOcean App Platform %s failed: HTTP %d %s',
                $action,
                $response->status(),
                $response->body(),
            ));
        }
    }
}
