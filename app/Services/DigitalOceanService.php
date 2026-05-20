<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
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
     * List all droplets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDroplets(?string $tag = null): array
    {
        $query = $tag !== null ? ['tag_name' => $tag] : [];
        $response = $this->request('get', '/droplets', $query);
        $this->assertSuccess($response, 'list droplets');
        $data = $response->json();
        $droplets = $data['droplets'] ?? $data['data'] ?? [];

        return is_array($droplets) ? $droplets : [];
    }

    /**
     * Get a single droplet by ID. Returns decoded droplet array.
     *
     * @return array<string, mixed>
     */
    /**
     * Whether the droplet still exists (404 means deleted / wrong account).
     *
     * @return array{state: 'present'|'gone'|'unknown', detail?: string}
     */
    public function inspectDropletPresence(int $id): array
    {
        $response = $this->request('get', '/droplets/'.$id);
        $status = $response->status();

        if ($status === 404) {
            return ['state' => 'gone'];
        }

        if ($response->successful()) {
            return ['state' => 'present'];
        }

        $detail = $response->json('message');
        if (! is_string($detail) || $detail === '') {
            $detail = $response->body();
        }
        if (! is_string($detail) || trim($detail) === '') {
            $detail = 'HTTP '.$status;
        }

        return ['state' => 'unknown', 'detail' => $detail];
    }

    public function getDroplet(int $id): array
    {
        $response = $this->request('get', '/droplets/'.$id);
        $this->assertSuccess($response, 'get droplet');
        $data = $response->json();
        $droplet = $data['droplet'] ?? $data;
        if (empty($droplet) || ! is_array($droplet)) {
            throw new \RuntimeException('DigitalOcean API did not return droplet.');
        }

        return $droplet;
    }

    /**
     * Create a new droplet. Returns droplet array (IP may not be available immediately).
     *
     * @param  array<int|string>  $sshKeyIds  Optional DO SSH key IDs or fingerprints
     * @param  array{
     *     ipv6?: bool,
     *     backups?: bool,
     *     monitoring?: bool,
     *     vpc_uuid?: string|null,
     *     tags?: list<string>,
     *     user_data?: string
     * }  $options  Matches DigitalOcean create-droplet request body (subset).
     */
    public function createDroplet(
        string $name,
        string $region,
        string $size,
        string|int $image,
        array $sshKeyIds = [],
        array $options = []
    ): array {
        $ipv6 = (bool) ($options['ipv6'] ?? false);
        $backups = (bool) ($options['backups'] ?? false);
        $monitoring = (bool) ($options['monitoring'] ?? false);
        $userData = (string) ($options['user_data'] ?? '');
        $rawVpc = $options['vpc_uuid'] ?? null;
        $vpcUuid = is_string($rawVpc) ? trim($rawVpc) : '';
        $tags = $options['tags'] ?? [];
        $tags = is_array($tags) ? array_values(array_filter($tags, static fn ($t) => is_string($t) && $t !== '')) : [];

        $body = [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => is_numeric($image) ? (int) $image : (string) $image,
            'backups' => $backups,
            'ipv6' => $ipv6,
            'monitoring' => $monitoring,
        ];
        if ($sshKeyIds !== []) {
            $body['ssh_keys'] = $sshKeyIds;
        }
        if ($userData !== '') {
            $body['user_data'] = $userData;
        }
        if ($vpcUuid !== '') {
            $body['vpc_uuid'] = $vpcUuid;
        }
        if ($tags !== []) {
            $body['tags'] = $tags;
        }

        $response = $this->request('post', '/droplets', $body);
        $this->assertSuccess($response, 'create droplet');
        $data = $response->json();
        $droplet = $data['droplet'] ?? $data;
        if (empty($droplet) || ! is_array($droplet)) {
            throw new \RuntimeException('DigitalOcean API did not return droplet.');
        }

        return $droplet;
    }

    /**
     * Get public IPv4 from a droplet array (API response shape).
     */
    public static function getDropletPublicIp(array $droplet): ?string
    {
        $networks = $droplet['networks'] ?? [];
        if (isset($networks['v4']) && is_array($networks['v4'])) {
            foreach ($networks['v4'] as $n) {
                if (($n['type'] ?? '') === 'public') {
                    $ip = $n['ip_address'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }
        if (isset($networks['v6']) && is_array($networks['v6'])) {
            foreach ($networks['v6'] as $n) {
                if (($n['type'] ?? '') === 'public') {
                    $ip = $n['ip_address'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }
        // Legacy shape: array of network objects
        if (isset($networks[0]) && is_array($networks)) {
            foreach ($networks as $n) {
                if (($n['type'] ?? '') === 'public' && ($n['version'] ?? '') === '4') {
                    $ip = $n['ip_address'] ?? $n['ipAddress'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get available regions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegions(): array
    {
        return $this->cachedCatalogList('do_regions', '/regions', 'regions');
    }

    /**
     * Get available sizes (plans).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSizes(): array
    {
        return $this->cachedCatalogList('do_sizes', '/sizes', 'sizes');
    }

    /**
     * List managed DOKS clusters in this account. Same caching shape as regions/sizes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getKubernetesClusters(): array
    {
        return $this->cachedCatalogList('do_kubernetes_clusters', '/kubernetes/clusters', 'kubernetes_clusters');
    }

    /**
     * Fetch a single DOKS cluster (status, node_pools with per-node statuses,
     * ha, version, region). Bypasses the list-cache because the poller needs
     * fresh data on every call. Returns null when the cluster has been deleted
     * out from under us (404) so the caller can stop polling cleanly.
     *
     * @return array<string, mixed>|null
     */
    public function getKubernetesCluster(string $clusterId): ?array
    {
        $response = $this->request('get', '/kubernetes/clusters/'.$clusterId);
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccess($response, 'get kubernetes cluster');
        $data = $response->json();
        $cluster = $data['kubernetes_cluster'] ?? null;

        return is_array($cluster) ? $cluster : null;
    }

    /**
     * Pull the YAML kubeconfig for a cluster — bearer-token credentials inside,
     * caller is responsible for encrypting at rest. Only useful once the cluster
     * has reached state=running (DO returns 503 / empty during provisioning).
     */
    public function getKubernetesClusterKubeconfig(string $clusterId): string
    {
        $response = $this->request('get', '/kubernetes/clusters/'.$clusterId.'/kubeconfig');
        $this->assertSuccess($response, 'get kubernetes cluster kubeconfig');

        return $response->body();
    }

    /**
     * Tear down a DOKS cluster the user provisioned through dply. DigitalOcean
     * deletes the cluster + node pools but NOT attached load balancers / block
     * storage (per their docs) — those linger on the bill until separately
     * removed. Returns true on 204, false on 404 (already gone).
     */
    public function deleteKubernetesCluster(string $clusterId): bool
    {
        $response = $this->request('delete', '/kubernetes/clusters/'.$clusterId);
        if ($response->status() === 404) {
            Cache::forget('do_kubernetes_clusters:'.sha1($this->token));

            return false;
        }
        $this->assertSuccess($response, 'delete kubernetes cluster');
        Cache::forget('do_kubernetes_clusters:'.sha1($this->token));

        return true;
    }

    /**
     * Read DO's published Kubernetes options (supported versions, regions, sizes
     * for DOKS specifically). The "versions" array is what we use to populate
     * the version dropdown on the create-cluster form — DO usually publishes
     * 3-4 supported minor versions with one flagged as default/recommended.
     *
     * @return array<string, mixed>
     */
    public function getKubernetesOptions(): array
    {
        $cacheKey = 'do_kubernetes_options:'.sha1($this->token);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('get', '/kubernetes/options');
        $this->assertSuccess($response, 'list kubernetes options');
        $data = $response->json();
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        Cache::put($cacheKey, $options, now()->addMinutes(60));

        return $options;
    }

    /**
     * Provision a new DOKS cluster. DO returns the cluster shell immediately
     * (with status.state="provisioning"); the actual node pool VMs take 5-10
     * minutes to come up. Callers should treat the returned cluster as
     * pending until status.state="running".
     *
     * Bypasses the cluster-list cache on success so a subsequent
     * getKubernetesClusters() call doesn't return the pre-create snapshot.
     *
     * @return array<string, mixed>
     */
    public function createKubernetesCluster(
        string $name,
        string $region,
        string $nodeSize,
        int $nodeCount,
        bool $ha = false,
        ?string $version = null,
        string $nodePoolName = 'default-pool',
    ): array {
        // DigitalOcean's create-cluster endpoint requires an explicit version
        // slug — passing nothing (or the literal "latest") trips the API into
        // "invalid version slug" / VersionFeatureDockerVpcBugFixed errors.
        // When the caller didn't specify one, fetch the published options and
        // use the first slug (DO orders them newest-first).
        $versionSlug = is_string($version) ? trim($version) : '';
        if ($versionSlug === '') {
            $versionSlug = $this->resolveLatestKubernetesVersionSlug();
        }

        $body = [
            'name' => $name,
            'region' => $region,
            'version' => $versionSlug,
            'ha' => $ha,
            'node_pools' => [[
                'size' => $nodeSize,
                'count' => $nodeCount,
                'name' => $nodePoolName,
            ]],
        ];

        $response = $this->request('post', '/kubernetes/clusters', $body);
        $this->assertSuccess($response, 'create kubernetes cluster');
        $data = $response->json();
        $cluster = $data['kubernetes_cluster'] ?? $data;
        if (! is_array($cluster) || empty($cluster)) {
            throw new \RuntimeException('DigitalOcean API did not return a kubernetes cluster.');
        }

        Cache::forget('do_kubernetes_clusters:'.sha1($this->token));

        return $cluster;
    }

    /**
     * Look up the newest published DOKS version slug from /kubernetes/options.
     * Used when the caller didn't pin a specific version on create.
     */
    private function resolveLatestKubernetesVersionSlug(): string
    {
        $options = $this->getKubernetesOptions();
        $versions = is_array($options['versions'] ?? null) ? $options['versions'] : [];
        foreach ($versions as $version) {
            if (! is_array($version)) {
                continue;
            }
            $slug = (string) ($version['slug'] ?? '');
            if ($slug !== '') {
                return $slug;
            }
        }

        throw new \RuntimeException('DigitalOcean returned no available Kubernetes versions; cannot create a cluster without a version slug.');
    }

    /**
     * Cache regions/sizes responses per token. The wizard renders these on every
     * step and they don't change often — a 10 minute cache keeps the page fast
     * even when the DO API is slow, and bounded HTTP timeouts (in request())
     * keep the worst-case render under ~10s instead of stalling for 30s+.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cachedCatalogList(string $kind, string $path, string $primaryKey): array
    {
        $cacheKey = $kind.':'.sha1($this->token);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('get', $path);
        $this->assertSuccess($response, 'list '.$primaryKey);
        $data = $response->json();
        $items = $data[$primaryKey] ?? $data['data'] ?? [];
        $items = is_array($items) ? $items : [];

        Cache::put($cacheKey, $items, now()->addMinutes(10));

        return $items;
    }

    /**
     * Create a DigitalOcean Functions (serverless) namespace. The returned
     * api_host + access_key are the OpenWhisk credentials a function deploy
     * needs — stored on the serverless host Server's meta.
     *
     * @return array{api_host: string, namespace: string, access_key: string, region: string}
     */
    public function createFunctionsNamespace(string $region, string $label): array
    {
        $response = $this->request('post', '/functions/namespaces', [
            'region' => $region,
            'label' => $label,
        ]);
        $this->assertSuccess($response, 'create functions namespace');

        $ns = $response->json('namespace');
        $ns = is_array($ns) ? $ns : [];

        // OpenWhisk (which backs DO Functions) authenticates with a
        // `uuid:key` pair — the deployer splits the access key on the colon.
        // DO returns `uuid` and `key` separately, so recombine them here.
        $uuid = (string) ($ns['uuid'] ?? '');
        $key = (string) ($ns['key'] ?? '');
        $accessKey = ($uuid !== '' && $key !== '') ? $uuid.':'.$key : $key;

        return [
            'api_host' => (string) ($ns['api_host'] ?? ''),
            'namespace' => (string) ($ns['namespace'] ?? $ns['uuid'] ?? ''),
            'access_key' => $accessKey,
            'region' => (string) ($ns['region'] ?? $region),
        ];
    }

    /**
     * Validate token with a lightweight account endpoint.
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/account');
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Get available images (distributions, snapshots).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getImages(): array
    {
        $response = $this->request('get', '/images');
        $this->assertSuccess($response, 'list images');
        $data = $response->json();
        $images = $data['images'] ?? $data['data'] ?? [];

        return is_array($images) ? $images : [];
    }

    /**
     * List account SSH keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSshKeys(): array
    {
        $response = $this->request('get', '/account/keys');
        $this->assertSuccess($response, 'list SSH keys');
        $data = $response->json();
        $keys = $data['ssh_keys'] ?? $data['data'] ?? [];

        return is_array($keys) ? $keys : [];
    }

    /**
     * Add an SSH public key to the account. Returns key array with id.
     *
     * @return array<string, mixed>
     */
    public function addSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/account/keys', [
            'name' => $name,
            'public_key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');
        $data = $response->json();
        $key = $data['ssh_key'] ?? $data;
        if (empty($key) || ! is_array($key)) {
            throw new \RuntimeException('DigitalOcean API did not return SSH key.');
        }

        return $key;
    }

    /**
     * Delete an account SSH key by its DO numeric id or fingerprint.
     */
    public function deleteSshKey(int|string $idOrFingerprint): void
    {
        $value = is_string($idOrFingerprint) ? trim($idOrFingerprint) : (string) $idOrFingerprint;
        if ($value === '') {
            throw new \InvalidArgumentException('SSH key id or fingerprint is required.');
        }

        $response = $this->request('delete', '/account/keys/'.rawurlencode($value));
        $this->assertSuccess($response, 'delete SSH key');
    }

    /**
     * Whether the domain exists in this DigitalOcean account (Networking → Domains).
     */
    public function domainExistsInAccount(string $domain): bool
    {
        return $this->fetchDomain($domain) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $encoded = rawurlencode($domain);
        $response = $this->request('get', '/domains/'.$encoded);
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccess($response, 'get domain');
        $data = $response->json();
        $payload = $data['domain'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDomainRecords(string $domain, array $query = []): array
    {
        $response = $this->request('get', '/domains/'.$domain.'/records', $query);
        $this->assertSuccess($response, 'list domain records');
        $data = $response->json();
        $records = $data['domain_records'] ?? $data['data'] ?? [];

        return is_array($records) ? $records : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDomainRecord(string $domain, string $type, string $name, ?string $data = null): ?array
    {
        $type = strtoupper($type);
        $records = $this->getDomainRecords($domain, ['type' => $type, 'name' => $name]);

        if ($records === []) {
            $records = $this->getDomainRecords($domain);
        }

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            if (strtoupper((string) ($record['type'] ?? '')) !== $type) {
                continue;
            }

            if ((string) ($record['name'] ?? '') !== $name) {
                continue;
            }

            if ($data !== null && (string) ($record['data'] ?? '') !== $data) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createDomainRecord(
        string $domain,
        string $type,
        string $name,
        string $data,
        int $ttl = 60
    ): array {
        $response = $this->request('post', '/domains/'.$domain.'/records', [
            'type' => strtoupper($type),
            'name' => $name,
            'data' => $data,
            'ttl' => $ttl,
        ]);
        $this->assertSuccess($response, 'create domain record');
        $payload = $response->json();
        $record = $payload['domain_record'] ?? $payload;

        if (! is_array($record) || $record === []) {
            throw new \RuntimeException('DigitalOcean API did not return a domain record.');
        }

        return $record;
    }

    public function deleteDomainRecord(string $domain, int $recordId): void
    {
        $response = $this->request('delete', '/domains/'.$domain.'/records/'.$recordId);
        $this->assertSuccess($response, 'delete domain record');
    }

    /**
     * Delete a droplet by ID.
     */
    public function destroyDroplet(int $id): void
    {
        $response = $this->request('delete', '/droplets/'.$id);
        $this->assertSuccess($response, 'delete droplet');
    }

    /**
     * Issue a power_off action against a droplet. Snapshots require the droplet
     * to be off — DO will accept "snapshot" against a running droplet but uses a
     * crash-consistent freeze that is less reliable for application servers.
     *
     * @return array<string, mixed> action payload
     */
    public function powerOffDroplet(int $id): array
    {
        $response = $this->request('post', '/droplets/'.$id.'/actions', ['type' => 'power_off']);
        $this->assertSuccess($response, 'power off droplet');

        return $this->extractAction($response->json(), 'power off droplet');
    }

    /**
     * Trigger a snapshot of the droplet's disk into a custom image.
     *
     * @return array<string, mixed> action payload
     */
    public function snapshotDroplet(int $id, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Snapshot name is required.');
        }

        $response = $this->request('post', '/droplets/'.$id.'/actions', [
            'type' => 'snapshot',
            'name' => $name,
        ]);
        $this->assertSuccess($response, 'snapshot droplet');

        return $this->extractAction($response->json(), 'snapshot droplet');
    }

    /**
     * Fetch a droplet-scoped action so callers can poll for completion.
     *
     * @return array<string, mixed>
     */
    public function getDropletAction(int $dropletId, int $actionId): array
    {
        $response = $this->request('get', '/droplets/'.$dropletId.'/actions/'.$actionId);
        $this->assertSuccess($response, 'get droplet action');

        return $this->extractAction($response->json(), 'get droplet action');
    }

    /**
     * Block until a droplet action completes or errors.
     *
     * @param  int  $timeoutSeconds  Hard cap; long snapshots can run several minutes.
     * @param  int  $pollSeconds  Poll interval; snapshot actions only advance every 10–30s.
     * @param  callable(array<string, mixed>): void|null  $onTick
     * @return array<string, mixed> Final action payload (status === 'completed' on success)
     */
    public function waitForDropletAction(
        int $dropletId,
        int $actionId,
        int $timeoutSeconds = 1800,
        int $pollSeconds = 10,
        ?callable $onTick = null,
    ): array {
        $deadline = time() + max(30, $timeoutSeconds);
        $pollSeconds = max(2, $pollSeconds);

        while (true) {
            $action = $this->getDropletAction($dropletId, $actionId);
            if ($onTick !== null) {
                $onTick($action);
            }

            $status = strtolower((string) ($action['status'] ?? ''));
            if ($status === 'completed') {
                return $action;
            }
            if ($status === 'errored') {
                throw new \RuntimeException('DigitalOcean action errored: '.json_encode($action));
            }
            if (time() >= $deadline) {
                throw new \RuntimeException('Timed out waiting for DigitalOcean action '.$actionId.' (status='.$status.').');
            }

            sleep($pollSeconds);
        }
    }

    /**
     * List custom snapshots, optionally filtered to droplet snapshots.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSnapshots(?string $resourceType = 'droplet'): array
    {
        $query = $resourceType !== null && $resourceType !== '' ? ['resource_type' => $resourceType] : [];
        $response = $this->request('get', '/snapshots', $query);
        $this->assertSuccess($response, 'list snapshots');
        $data = $response->json();
        $snapshots = $data['snapshots'] ?? $data['data'] ?? [];

        return is_array($snapshots) ? $snapshots : [];
    }

    /**
     * Delete a snapshot by ID. Snapshot IDs are returned as strings by the API.
     */
    public function deleteSnapshot(string $snapshotId): void
    {
        $snapshotId = trim($snapshotId);
        if ($snapshotId === '') {
            throw new \InvalidArgumentException('Snapshot id is required.');
        }

        $response = $this->request('delete', '/snapshots/'.rawurlencode($snapshotId));
        $this->assertSuccess($response, 'delete snapshot');
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>
     */
    private function extractAction($payload, string $action): array
    {
        if (! is_array($payload)) {
            throw new \RuntimeException("DigitalOcean API did not return an action for {$action}.");
        }

        $data = $payload['action'] ?? $payload;
        if (! is_array($data) || $data === []) {
            throw new \RuntimeException("DigitalOcean API did not return an action for {$action}.");
        }

        return $data;
    }

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
