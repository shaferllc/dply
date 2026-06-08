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
 * Used by the dply cloud layer to provision/redeploy container apps
 * on DO. The api_token credential here is the same shape as the
 * regular DigitalOcean credential — App Platform shares the
 * read+write token scope with droplets/dns/etc.
 *
 * Methods are intentionally minimal — just what the dply cloud
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
     * When $autoscaling is non-null the web service emits an
     * `autoscaling` block and OMITS the fixed `instance_count` — the
     * two are mutually exclusive on App Platform. $healthCheck, when
     * non-null, adds a `health_check` block to the web service.
     *
     * @param  array<string, string>  $envVars
     * @param  list<array<string, mixed>>  $workers  Optional `workers`
     *                                               components (background processes — queue workers / scheduler)
     *                                               to add to the spec alongside the web service.
     * @param  array<string, mixed>|null  $autoscaling  Optional `autoscaling` block.
     * @param  array<string, mixed>|null  $healthCheck  Optional `health_check` block.
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
        string $instanceSizeSlug = 'basic-xxs',
        array $workers = [],
        ?array $autoscaling = null,
        ?array $healthCheck = null,
        array $jobs = [],
        array $alerts = [],
        ?string $registryCredentials = null,
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
            'image' => self::imageSpecBlock($image, $registryCredentials),
            'http_port' => $port,
            'instance_size_slug' => $instanceSizeSlug,
            'envs' => $envSpec,
        ];
        // Autoscaling and a fixed instance_count are mutually
        // exclusive on App Platform — emit exactly one.
        if ($autoscaling !== null) {
            $service['autoscaling'] = $autoscaling;
        } else {
            $service['instance_count'] = max(1, $instanceCount);
        }
        if ($healthCheck !== null) {
            $service['health_check'] = $healthCheck;
        }

        $spec = [
            'name' => $appName,
            'region' => $region,
            'services' => [$service],
        ];
        if ($workers !== []) {
            $spec['workers'] = array_values($workers);
        }
        if ($jobs !== []) {
            $spec['jobs'] = array_values($jobs);
        }
        if ($alerts !== []) {
            $spec['alerts'] = array_values($alerts);
        }

        $response = $this->request('post', '/apps', ['spec' => $spec]);
        $this->assertSuccess($response, 'create app');
        $data = $response->json('app') ?? [];

        return [
            'id' => (string) ($data['id'] ?? ''),
            'default_ingress' => $data['default_ingress'] ?? null,
            'alerts' => is_array($data['spec']['alerts'] ?? null) ? $data['spec']['alerts'] : [],
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
     * @param  list<array<string, mixed>>  $workers  Optional `workers`
     *                                               components (background processes — queue workers / scheduler)
     *                                               to add to the spec alongside the web service.
     * @param  array<string, mixed>|null  $autoscaling  Optional `autoscaling` block (omits fixed instance_count).
     * @param  array<string, mixed>|null  $healthCheck  Optional `health_check` block.
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
        string $instanceSizeSlug = 'basic-xxs',
        array $workers = [],
        ?array $autoscaling = null,
        ?array $healthCheck = null,
        array $jobs = [],
        array $alerts = [],
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
            'instance_size_slug' => $instanceSizeSlug,
            'envs' => $envSpec,
        ];
        // Autoscaling and a fixed instance_count are mutually
        // exclusive on App Platform — emit exactly one.
        if ($autoscaling !== null) {
            $service['autoscaling'] = $autoscaling;
        } else {
            $service['instance_count'] = max(1, $instanceCount);
        }
        if ($healthCheck !== null) {
            $service['health_check'] = $healthCheck;
        }

        if (is_string($dockerfilePath) && $dockerfilePath !== '') {
            $service['dockerfile_path'] = $dockerfilePath;
        }

        $spec = [
            'name' => $appName,
            'region' => $region,
            'services' => [$service],
        ];
        if ($workers !== []) {
            $spec['workers'] = array_values($workers);
        }
        if ($jobs !== []) {
            $spec['jobs'] = array_values($jobs);
        }
        if ($alerts !== []) {
            $spec['alerts'] = array_values($alerts);
        }

        $response = $this->request('post', '/apps', ['spec' => $spec]);
        $this->assertSuccess($response, 'create app from source');
        $data = $response->json('app') ?? [];

        return [
            'id' => (string) ($data['id'] ?? ''),
            'default_ingress' => $data['default_ingress'] ?? null,
            'alerts' => is_array($data['spec']['alerts'] ?? null) ? $data['spec']['alerts'] : [],
        ];
    }

    /**
     * Update the destinations for an existing alert. DO assigns each
     * alert in the app spec a generated ID; pass that here along with
     * the slack_webhooks/emails block to wire notifications. Called
     * after createApp returns the alert IDs.
     *
     * @param  array{slack_webhooks?: list<array{url: string, channel?: string}>, emails?: list<string>}  $destinations
     */
    public function updateAlertDestinations(string $appId, string $alertId, array $destinations): void
    {
        $response = $this->request(
            'post',
            '/apps/'.$appId.'/alerts/'.$alertId.'/destinations',
            $destinations,
        );
        $this->assertSuccess($response, 'update alert destinations');
    }

    /**
     * POST /apps/propose — run a spec through DO's validators and get
     * back the estimated monthly cost without actually creating the
     * app. Used by the dply Cloud Create form to show live cost and
     * to catch spec mismatches (autoscaling-on-Basic, invalid regions,
     * missing image creds) before the user submits.
     *
     * Returns {ok, app_cost, error?} so the form can render either
     * a price or an inline error message.
     *
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, app_cost: ?float, error: ?string}
     */
    public function proposeApp(array $spec): array
    {
        $response = $this->request('post', '/apps/propose', ['spec' => $spec]);

        if ($response->status() >= 400) {
            $body = $response->json();
            $message = is_array($body) && is_string($body['message'] ?? null)
                ? $body['message']
                : $response->body();

            return ['ok' => false, 'app_cost' => null, 'error' => $message];
        }

        $cost = $response->json('app_cost');

        return [
            'ok' => true,
            'app_cost' => is_numeric($cost) ? (float) $cost : null,
            'error' => null,
        ];
    }

    /**
     * List recent deployments for an app. Returns at most $limit
     * deployments newest-first.
     *
     * @return list<array<string, mixed>>
     */
    public function listDeployments(string $appId, int $limit = 10): array
    {
        $response = $this->request('get', '/apps/'.$appId.'/deployments?per_page='.max(1, $limit));
        $this->assertSuccess($response, 'list deployments');
        $deployments = $response->json('deployments') ?? [];

        return is_array($deployments) ? array_values($deployments) : [];
    }

    /**
     * Fetch a single deployment by id — phases, per-component progress,
     * created/started/finished timestamps. Used by the dply deploy
     * detail page to show the full rollout story.
     *
     * @return array<string, mixed>
     */
    public function getDeployment(string $appId, string $deploymentId): array
    {
        $response = $this->request('get', '/apps/'.$appId.'/deployments/'.$deploymentId);
        $this->assertSuccess($response, 'get deployment');
        $deployment = $response->json('deployment') ?? [];

        return is_array($deployment) ? $deployment : [];
    }

    /**
     * Cancel an in-progress deployment. Idempotent — DO returns 422
     * for deployments that already terminated (success/failure), so
     * we treat 2xx and 422 both as a no-op success.
     */
    public function cancelDeployment(string $appId, string $deploymentId): void
    {
        $response = $this->request('post', '/apps/'.$appId.'/deployments/'.$deploymentId.'/cancel', []);

        if ($response->status() === 422) {
            // Already terminal — nothing to cancel. Don't error.
            return;
        }
        $this->assertSuccess($response, 'cancel deployment');
    }

    /**
     * Fetch the latest deployment log link for an app. DO returns a
     * presigned `historic_urls[0]` URL the operator can curl/open;
     * for in-progress deployments it returns a `live_url` instead.
     * We surface whichever is set.
     *
     * @return array{deployment_id: ?string, url: ?string}
     */
    public function getLatestDeploymentLogs(string $appId, string $type = 'DEPLOY'): array
    {
        $deploymentsResponse = $this->request('get', '/apps/'.$appId.'/deployments?per_page=1');
        $this->assertSuccess($deploymentsResponse, 'list deployments');
        $deployments = $deploymentsResponse->json('deployments') ?? [];
        if (! is_array($deployments) || $deployments === []) {
            return ['deployment_id' => null, 'url' => null];
        }

        $deploymentId = (string) ($deployments[0]['id'] ?? '');
        if ($deploymentId === '') {
            return ['deployment_id' => null, 'url' => null];
        }

        $logsResponse = $this->request('get', '/apps/'.$appId.'/deployments/'.$deploymentId.'/logs?type='.$type);
        $this->assertSuccess($logsResponse, 'get deployment logs');
        $payload = $logsResponse->json() ?? [];
        $url = is_array($payload['historic_urls'] ?? null) && isset($payload['historic_urls'][0])
            ? (string) $payload['historic_urls'][0]
            : (is_string($payload['live_url'] ?? null) ? (string) $payload['live_url'] : null);

        return ['deployment_id' => $deploymentId, 'url' => $url];
    }

    /**
     * Fetch RUN (runtime) logs for the active deployment of an app.
     *
     * DO's active-deployment logs endpoint
     * (/v2/apps/{app_id}/components/{component_name}/logs) returns
     * `historic_urls` (archived log files) and/or a `live_url`
     * (real-time stream). We surface whichever archive URL is set —
     * the operator / dashboard fetches the actual text from it.
     *
     * type=RUN selects live runtime logs; type=RUN_RESTARTED would
     * return crash logs. The component defaults to "web" — the name
     * the dply provision flow always gives the web service.
     *
     * @return array{url: ?string, live_url: ?string}
     */
    public function getRuntimeLogs(string $appId, string $component = 'web'): array
    {
        $path = '/apps/'.$appId.'/components/'.rawurlencode($component).'/logs?type=RUN';
        $response = $this->request('get', $path);
        $this->assertSuccess($response, 'get runtime logs');
        $payload = $response->json() ?? [];

        $url = is_array($payload['historic_urls'] ?? null) && isset($payload['historic_urls'][0])
            ? (string) $payload['historic_urls'][0]
            : null;
        $liveUrl = is_string($payload['live_url'] ?? null) ? (string) $payload['live_url'] : null;

        return ['url' => $url, 'live_url' => $liveUrl];
    }

    /**
     * Fetch an App Platform monitoring metric for an app over a
     * UNIX-timestamp window. $metric is the DO metric path segment —
     * one of: 'cpu_percentage', 'memory_percentage', 'restart_count'.
     *
     * The response is Prometheus-style: a `matrix` result where each
     * series carries a `values` array of [unix-ts, "string-value"]
     * pairs. We flatten the first series into {t, v} points; an empty
     * or unexpected response degrades to an empty list.
     *
     * Docs: GET /v2/monitoring/metrics/apps/{metric}
     *
     * @return list<array{t: int, v: float}>
     */
    public function getAppMetric(string $appId, string $metric, int $start, int $end, string $component = 'web'): array
    {
        $query = http_build_query([
            'app_id' => $appId,
            'app_component' => $component,
            'start' => (string) $start,
            'end' => (string) $end,
        ]);
        $response = $this->request('get', '/monitoring/metrics/apps/'.$metric.'?'.$query);
        $this->assertSuccess($response, 'get app metric '.$metric);

        $payload = $response->json() ?? [];
        $result = $payload['data']['result'] ?? null;
        if (! is_array($result) || $result === []) {
            return [];
        }

        // Prefer the series matching our component; fall back to the
        // first series when no label match (single-component apps).
        $series = null;
        foreach ($result as $entry) {
            if (is_array($entry) && (string) ($entry['metric']['app_component'] ?? '') === $component) {
                $series = $entry;
                break;
            }
        }
        if ($series === null) {
            $series = is_array($result[0] ?? null) ? $result[0] : null;
        }
        if (! is_array($series) || ! is_array($series['values'] ?? null)) {
            return [];
        }

        $points = [];
        foreach ($series['values'] as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            $points[] = [
                't' => (int) $pair[0],
                'v' => (float) $pair[1],
            ];
        }

        return $points;
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
     * region picker.
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
        return self::parseImageRefStatic($image);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function parseImageRefStatic(string $image): array
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
     * Build DO App Platform's `image` block for a Docker image ref.
     * DO requires `registry` (account/namespace) and `repository` (image
     * name) as separate fields — packing the namespace into `repository`
     * trips "Image does not exist or is private" at create time.
     *
     *   "nginxdemos/hello:latest"              → DOCKER_HUB, registry=nginxdemos, repository=hello
     *   "nginx:1.27"                           → DOCKER_HUB, repository=library/nginx
     *   "ghcr.io/acme/api:v1"                  → GHCR, registry=acme, repository=api
     *   "registry.digitalocean.com/acme/api:v1" → DOCR, registry=acme, repository=api
     *
     * When `$registryCredentials` is supplied it's threaded into the
     * spec verbatim (DO accepts a `username:token` string for GHCR
     * and Docker Hub private repos). DOCR doesn't need credentials —
     * the app's DO PAT authenticates against DOCR transparently when
     * its scope covers `registry:read`.
     *
     * @return array<string, string>
     */
    public static function imageSpecBlock(string $image, ?string $registryCredentials = null): array
    {
        [$registryHost, $repository, $tag] = self::parseImageRefStatic($image);

        $registryType = match (true) {
            $registryHost === 'registry.digitalocean.com' => 'DOCR',
            $registryHost === 'ghcr.io' => 'GHCR',
            str_ends_with($registryHost, 'docker.io') => 'DOCKER_HUB',
            default => 'DOCKER_HUB',
        };

        $block = [
            'registry_type' => $registryType,
            'repository' => $repository,
            'tag' => $tag,
        ];

        // For registries that namespace by account (DOCR, GHCR, Docker
        // Hub user repos), DO wants `registry` (account) and
        // `repository` (image name) split. The Docker Hub `library/*`
        // namespace is special-cased — DO accepts the packed form for
        // those and the explicit split for everything else.
        if (in_array($registryType, ['DOCR', 'GHCR'], true) && str_contains($repository, '/')) {
            [$namespace, $name] = explode('/', $repository, 2);
            $block['registry'] = $namespace;
            $block['repository'] = $name;
        } elseif ($registryType === 'DOCKER_HUB' && str_contains($repository, '/')) {
            [$namespace, $name] = explode('/', $repository, 2);
            if ($namespace !== 'library') {
                $block['registry'] = $namespace;
                $block['repository'] = $name;
            }
        }

        if (is_string($registryCredentials) && $registryCredentials !== '') {
            $block['registry_credentials'] = $registryCredentials;
        }

        return $block;
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
