<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    protected string $baseUrl = 'https://api.hetzner.cloud/v1';

    protected string $token;

    /**
     * Accepts either a customer's connected ProviderCredential (BYO servers) or a
     * raw API token (dply-managed servers provisioned on dply's own Hetzner project).
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : trim($credentialOrToken);

        if (empty($token)) {
            throw new \InvalidArgumentException('Hetzner API token is required.');
        }

        $this->token = $token;
    }

    /**
     * Build a service bound to a raw API token (e.g. dply's platform Hetzner project).
     */
    public static function fromToken(string $token): self
    {
        return new self($token);
    }

    /**
     * Register an SSH public key in the Hetzner project. Returns key array with id.
     *
     * @return array<string, mixed>
     */
    public function addSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/ssh_keys', [
            'name' => $name,
            'public_key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');

        $data = $response->json();
        $key = $data['ssh_key'] ?? null;
        if (! is_array($key) || ! isset($key['id'])) {
            throw new \RuntimeException('Hetzner API did not return SSH key id.');
        }

        return $key;
    }

    /**
     * Delete an SSH key by ID (used to clean up a temp key after a snapshot bake).
     */
    public function deleteSshKey(int $id): void
    {
        $response = $this->request('delete', "/ssh_keys/{$id}");
        $this->assertSuccess($response, 'delete SSH key');
    }

    /**
     * Create a new server (instance) and return its ID.
     *
     * @param  array<int|string>  $sshKeyIds  Hetzner SSH key IDs or names
     */
    /**
     * @param  array<int|string>  $sshKeyIds  Hetzner SSH key IDs or names
     * @param  list<int>  $firewallIds  Cloud Firewall IDs to attach at boot (atomic — no unreachable window)
     */
    public function createInstance(
        string $name,
        string $location,
        string $serverType,
        string $image,
        array $sshKeyIds = [],
        string $userData = '',
        array $firewallIds = [],
        ?int $networkId = null,
    ): int {
        $body = [
            'name' => $name,
            'location' => $location,
            'server_type' => $serverType,
            'image' => $image,
        ];
        if ($sshKeyIds !== []) {
            $body['ssh_keys'] = $sshKeyIds;
        }
        if ($userData !== '') {
            $body['user_data'] = $userData;
        }
        if ($firewallIds !== []) {
            $body['firewalls'] = array_map(
                static fn ($id) => ['firewall' => (int) $id],
                array_values($firewallIds)
            );
        }
        if ($networkId !== null) {
            $body['networks'] = [$networkId];
        }

        $response = $this->request('post', '/servers', $body);
        $this->assertSuccess($response, 'create server');

        $data = $response->json();
        $server = $data['server'] ?? null;
        if (! $server || ! isset($server['id'])) {
            throw new \RuntimeException('Hetzner API did not return server id.');
        }

        return (int) $server['id'];
    }

    /**
     * Get instance (server) by ID. Returns decoded JSON server object.
     */
    public function getInstance(int $id): array
    {
        $response = $this->request('get', "/servers/{$id}");
        $this->assertSuccess($response, 'get server');

        $data = $response->json();
        $server = $data['server'] ?? null;
        if (! $server) {
            throw new \RuntimeException('Hetzner API did not return server.');
        }

        return $server;
    }

    /**
     * Get public IPv4 from a server array returned by getInstance().
     */
    public static function getPublicIp(array $server): ?string
    {
        $publicNet = $server['public_net'] ?? [];
        $ipv4 = $publicNet['ipv4'] ?? null;
        if ($ipv4 === null) {
            return null;
        }

        return $ipv4['ip'] ?? null;
    }

    /**
     * Get the private IP assigned by a Hetzner private network.
     * Returns the first private_net IP found on the server object.
     */
    public static function getPrivateIp(array $server): ?string
    {
        $privateNets = $server['private_net'] ?? [];
        foreach ($privateNets as $net) {
            $ip = $net['ip'] ?? null;
            if (is_string($ip) && $ip !== '') {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Create a new Hetzner private network and return its ID.
     *
     * @param  array<int>  $serverIds  Provider server IDs to attach immediately
     */
    /**
     * @param  list<string>  $zones  Network zones to add subnets for (e.g. ['eu-central', 'us-east']).
     *                               At least one is required — Hetzner will not assign private IPs without a subnet.
     */
    public function createNetwork(string $name, string $ipRange = '10.0.0.0/8', array $zones = ['eu-central']): int
    {
        if (empty($zones)) {
            $zones = ['eu-central'];
        }

        $subnets = array_values(array_map(static fn (string $zone) => [
            'type' => 'cloud',
            'network_zone' => $zone,
            'ip_range' => $ipRange,
        ], array_unique($zones)));

        $body = [
            'name' => $name,
            'ip_range' => $ipRange,
            'subnets' => $subnets,
        ];

        $response = $this->request('post', '/networks', $body);
        $this->assertSuccess($response, 'create network');

        $data = $response->json();
        $networkId = (int) ($data['network']['id'] ?? 0);
        if ($networkId === 0) {
            throw new \RuntimeException('Hetzner API did not return network id.');
        }

        return $networkId;
    }

    /**
     * Attach a server to a Hetzner private network after creation.
     * The API is async; callers that need the assigned IP should re-fetch
     * getInstance() after a short delay.
     */
    public function attachServerToNetwork(int $serverId, int $networkId): void
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/attach_to_network", [
            'network' => $networkId,
        ]);
        $this->assertSuccess($response, 'attach server to network');
    }

    public function detachServerFromNetwork(int $serverId, int $networkId): void
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/detach_from_network", [
            'network' => $networkId,
        ]);
        $this->assertSuccess($response, 'detach server from network');
    }

    /**
     * Map a Hetzner location code to its network zone.
     * Used when creating subnets so the zone matches the server's region.
     */
    public static function networkZoneForRegion(string $region): string
    {
        return match (strtolower($region)) {
            'ash' => 'us-east',
            'hil' => 'us-west',
            'sin' => 'ap-southeast',
            default => 'eu-central', // fsn1, nbg1, hel1
        };
    }

    /**
     * Get a single private network by ID, including its routes.
     *
     * @return array{id:int,name:string,ip_range:string,routes:list<array{destination:string,gateway:string}>}
     */
    public function getNetwork(int $id): array
    {
        $response = $this->request('get', "/networks/{$id}");
        $this->assertSuccess($response, 'get network');

        $network = $response->json()['network'] ?? null;
        if (! $network) {
            throw new \RuntimeException('Hetzner API did not return network.');
        }

        return [
            'id' => (int) $network['id'],
            'name' => (string) ($network['name'] ?? ''),
            'ip_range' => (string) ($network['ip_range'] ?? ''),
            'routes' => array_map(static fn ($r) => [
                'destination' => (string) ($r['destination'] ?? ''),
                'gateway' => (string) ($r['gateway'] ?? ''),
            ], $network['routes'] ?? []),
        ];
    }

    /**
     * Add a static route to a private network.
     * destination — CIDR the route covers (e.g. "192.168.1.0/24")
     * gateway     — IP of the server on the network that forwards the traffic
     */
    public function addNetworkRoute(int $networkId, string $destination, string $gateway): void
    {
        $response = $this->request('post', "/networks/{$networkId}/actions/add_route", [
            'destination' => $destination,
            'gateway' => $gateway,
        ]);
        $this->assertSuccess($response, 'add network route');
    }

    /**
     * Remove a static route from a private network.
     */
    public function deleteNetworkRoute(int $networkId, string $destination, string $gateway): void
    {
        $response = $this->request('post', "/networks/{$networkId}/actions/delete_route", [
            'destination' => $destination,
            'gateway' => $gateway,
        ]);
        $this->assertSuccess($response, 'delete network route');
    }

    /**
     * List private networks available in this account.
     *
     * @return array<int, array{id: int, name: string, ip_range: string}>
     */
    public function listNetworks(): array
    {
        $response = $this->request('get', '/networks');
        $this->assertSuccess($response, 'list networks');
        $data = $response->json();

        return array_map(static fn ($n) => [
            'id' => (int) ($n['id'] ?? 0),
            'name' => (string) ($n['name'] ?? ''),
            'ip_range' => (string) ($n['ip_range'] ?? ''),
        ], $data['networks'] ?? []);
    }

    /**
     * Destroy (delete) an instance by ID.
     */
    public function destroyInstance(int $id): void
    {
        $response = $this->request('delete', "/servers/{$id}");
        $this->assertSuccess($response, 'delete server');
    }

    // ─── Server actions (snapshot bake) ─────────────────────────────────────────

    /**
     * Power a server off (hard stop). Returns the action object so the caller
     * can poll {@see waitForAction}. A powered-off server yields a
     * crash-consistent snapshot.
     *
     * @return array<string, mixed>
     */
    public function powerOffServer(int $id): array
    {
        $response = $this->request('post', "/servers/{$id}/actions/poweroff");
        $this->assertSuccess($response, 'power off server');

        $action = $response->json()['action'] ?? null;
        if (! is_array($action) || ! isset($action['id'])) {
            throw new \RuntimeException('Hetzner API did not return a power-off action.');
        }

        return $action;
    }

    /**
     * Create a snapshot image from a server. Hetzner snapshots are GLOBAL across
     * locations (usable to create servers in any region). Returns
     * ['action' => <action>, 'image_id' => <int>].
     *
     * @param  array<string, string>  $labels
     * @return array{action: array<string, mixed>, image_id: int}
     */
    public function createImageFromServer(int $id, string $description, array $labels = []): array
    {
        $body = [
            'type' => 'snapshot',
            'description' => $description,
        ];
        if ($labels !== []) {
            $body['labels'] = $labels;
        }

        $response = $this->request('post', "/servers/{$id}/actions/create_image", $body);
        $this->assertSuccess($response, 'create image from server');

        $data = $response->json();
        $action = $data['action'] ?? null;
        $imageId = (int) ($data['image']['id'] ?? 0);
        if (! is_array($action) || ! isset($action['id']) || $imageId <= 0) {
            throw new \RuntimeException('Hetzner API did not return a create_image action + image id.');
        }

        return ['action' => $action, 'image_id' => $imageId];
    }

    /**
     * Fetch a single image (snapshot) by ID — used to read its disk size once
     * the create_image action completes.
     *
     * @return array<string, mixed>
     */
    public function getImage(int $imageId): array
    {
        $response = $this->request('get', "/images/{$imageId}");
        $this->assertSuccess($response, 'get image');

        $image = $response->json()['image'] ?? null;
        if (! is_array($image)) {
            throw new \RuntimeException('Hetzner API did not return an image.');
        }

        return $image;
    }

    /**
     * Delete a snapshot/image by ID. No-op-safe on a 404 (already gone).
     */
    public function deleteImage(int $imageId): void
    {
        if ($imageId <= 0) {
            throw new \InvalidArgumentException('Image id is required.');
        }

        $response = $this->request('delete', "/images/{$imageId}");
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'delete image');
    }

    /**
     * Fetch a single action by ID from the global actions endpoint.
     *
     * @return array<string, mixed>
     */
    public function getAction(int $actionId): array
    {
        $response = $this->request('get', "/actions/{$actionId}");
        $this->assertSuccess($response, 'get action');

        $action = $response->json()['action'] ?? null;
        if (! is_array($action)) {
            throw new \RuntimeException('Hetzner API did not return an action.');
        }

        return $action;
    }

    /**
     * Poll an action until it reaches a terminal state. Throws on `error` or
     * timeout. $onTick receives each polled action snapshot.
     *
     * @param  callable(array<string, mixed>):void|null  $onTick
     */
    public function waitForAction(int $actionId, int $timeoutSeconds = 2400, int $pollSeconds = 10, ?callable $onTick = null): void
    {
        $deadline = time() + max(1, $timeoutSeconds);

        while (time() < $deadline) {
            $action = $this->getAction($actionId);
            $status = (string) ($action['status'] ?? '');

            if ($onTick !== null) {
                $onTick($action);
            }

            if ($status === 'success') {
                return;
            }

            if ($status === 'error') {
                $message = is_array($action['error'] ?? null)
                    ? (string) ($action['error']['message'] ?? 'unknown error')
                    : 'unknown error';
                throw new \RuntimeException("Hetzner action {$actionId} failed: {$message}");
            }

            sleep(max(1, $pollSeconds));
        }

        throw new \RuntimeException("Hetzner action {$actionId} did not finish within {$timeoutSeconds}s.");
    }

    // ─── Load Balancers ───────────────────────────────────────────────────────

    /**
     * Create a load balancer and return its ID.
     *
     * @param  array<int>  $targetServerProviderIds  Hetzner server IDs to add as targets immediately
     * @param  list<array{protocol:string,listen_port:int,destination_port:int}>  $services
     */
    public function createLoadBalancer(
        string $name,
        string $loadBalancerType,
        string $location,
        string $algorithm = 'round_robin',
        ?int $networkId = null,
        array $targetServerProviderIds = [],
        array $services = [],
    ): array {
        $body = [
            'name' => $name,
            'load_balancer_type' => $loadBalancerType,
            'location' => $location,
            'algorithm' => ['type' => $algorithm],
            'public_interface' => true,
        ];

        if ($networkId !== null) {
            $body['network'] = $networkId;
        }

        if ($targetServerProviderIds !== []) {
            $body['targets'] = array_map(static fn ($id) => [
                'type' => 'server',
                'server' => ['id' => (int) $id],
                'use_private_ip' => $networkId !== null,
            ], array_values($targetServerProviderIds));
        }

        if ($services !== []) {
            $body['services'] = array_map(static fn ($svc) => [
                'protocol' => $svc['protocol'],
                'listen_port' => (int) $svc['listen_port'],
                'destination_port' => (int) $svc['destination_port'],
                'health_check' => [
                    'protocol' => in_array($svc['protocol'], ['http', 'https'], true) ? 'http' : 'tcp',
                    'port' => (int) $svc['destination_port'],
                    'interval' => 15,
                    'timeout' => 10,
                    'retries' => 3,
                    'http' => in_array($svc['protocol'], ['http', 'https'], true)
                        ? ['path' => '/', 'status_codes' => ['2??', '3??'], 'tls' => false]
                        : null,
                ],
                'sticky_sessions' => ['enabled' => (bool) ($svc['sticky_sessions'] ?? false)],
            ], $services);
        }

        $response = $this->request('post', '/load_balancers', $body);
        $this->assertSuccess($response, 'create load balancer');

        $data = $response->json();
        $lb = $data['load_balancer'] ?? null;
        if (! $lb || ! isset($lb['id'])) {
            throw new \RuntimeException('Hetzner API did not return load balancer id.');
        }

        return $lb;
    }

    public function getLoadBalancer(int $id): array
    {
        $response = $this->request('get', "/load_balancers/{$id}");
        $this->assertSuccess($response, 'get load balancer');

        return $response->json()['load_balancer'] ?? throw new \RuntimeException('Hetzner API did not return load balancer.');
    }

    /** @return list<array<string, mixed>> */
    public function listLoadBalancers(): array
    {
        $response = $this->request('get', '/load_balancers');
        $this->assertSuccess($response, 'list load balancers');

        return $response->json()['load_balancers'] ?? [];
    }

    public function deleteLoadBalancer(int $id): void
    {
        $response = $this->request('delete', "/load_balancers/{$id}");
        if ($response->status() === 404) {
            return; // Already gone — idempotent.
        }
        $this->assertSuccess($response, 'delete load balancer');
    }

    public function addLoadBalancerTarget(int $lbId, int $serverProviderId, bool $usePrivateIp = false): void
    {
        $response = $this->request('post', "/load_balancers/{$lbId}/actions/add_target", [
            'type' => 'server',
            'server' => ['id' => $serverProviderId],
            'use_private_ip' => $usePrivateIp,
        ]);
        $this->assertSuccess($response, 'add load balancer target');
    }

    public function removeLoadBalancerTarget(int $lbId, int $serverProviderId): void
    {
        $response = $this->request('post', "/load_balancers/{$lbId}/actions/remove_target", [
            'type' => 'server',
            'server' => ['id' => $serverProviderId],
        ]);
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'remove load balancer target');
    }

    public static function getLbPublicIpv4(array $lb): ?string
    {
        foreach ($lb['public_net']['ipv4'] ?? [] as $entry) {
            if (isset($entry['ip'])) {
                return $entry['ip'];
            }
        }

        return $lb['public_net']['ipv4']['ip'] ?? null;
    }

    public static function getLbPrivateIp(array $lb): ?string
    {
        foreach ($lb['private_net'] ?? [] as $net) {
            if (isset($net['ip'])) {
                return $net['ip'];
            }
        }

        return null;
    }

    /**
     * List locations (for region dropdown).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLocations(): array
    {
        $response = $this->request('get', '/locations');
        $this->assertSuccess($response, 'list locations');
        $data = $response->json();

        return $data['locations'] ?? [];
    }

    /**
     * List server types (sizes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getServerTypes(): array
    {
        $response = $this->request('get', '/server_types');
        $this->assertSuccess($response, 'list server types');
        $data = $response->json();

        return $data['server_types'] ?? [];
    }

    /**
     * Validate token by listing servers (lightweight call).
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/servers', ['per_page' => 1]);
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Whether a DNS zone exists in this Hetzner Cloud project.
     */
    public function zoneExists(string $zoneName): bool
    {
        return $this->findZone($zoneName) !== null;
    }

    /**
     * Create a Hetzner DNS zone, or return the existing one if it's already
     * registered under this project. Idempotent. Used by the testing-zone
     * auto-provision path so the operator doesn't have to pre-create the
     * Dply-owned testing zones (e.g. on-dply.cc) in their Hetzner project.
     *
     * @return array<string, mixed>
     */
    public function createZone(string $zoneName): array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            throw new \RuntimeException('Zone name is required.');
        }

        $existing = $this->findZone($zoneName);
        if ($existing !== null) {
            return $existing;
        }

        // Hetzner DNS requires a `mode` on create — `primary` for zones
        // where Hetzner is authoritative (the case for Dply-owned testing
        // zones), `secondary` for slave zones pulled from another master.
        $response = $this->request('post', '/zones', [
            'name' => $zoneName,
            'mode' => 'primary',
        ]);
        $this->assertSuccess($response, 'create zone');

        $created = $response->json('zone');
        if (! is_array($created)) {
            $created = $this->findZone($zoneName);
        }

        return is_array($created) ? $created : ['name' => $zoneName];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findZone(string $zoneName): ?array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = $this->request('get', '/zones', ['name' => $zoneName]);
        $this->assertSuccess($response, 'list zones');

        foreach ($response->json('zones') ?? [] as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $name = strtolower((string) ($zone['name'] ?? ''));
            if ($name === $zoneName) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Create or replace an A/AAAA-style RRset and return the RRset payload.
     *
     * @return array<string, mixed>
     */
    public function upsertZoneRecord(
        string $zoneName,
        string $type,
        string $recordName,
        string $value,
        int $ttl = 60
    ): array {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $existing = $this->getZoneRrset($zoneName, $rrName, $type);
        if ($existing !== null) {
            $response = $this->request(
                'post',
                "/zones/{$zoneKey}/rrsets/{$rrPath}/actions/set_records",
                [
                    'records' => [
                        ['value' => $value],
                    ],
                ]
            );
            $this->assertSuccess($response, 'set zone record');

            return $this->getZoneRrset($zoneName, $rrName, $type) ?? $existing;
        }

        $response = $this->request('post', "/zones/{$zoneKey}/rrsets", [
            'name' => $rrName,
            'type' => $type,
            'ttl' => $ttl,
            'records' => [
                ['value' => $value],
            ],
        ]);
        $this->assertSuccess($response, 'create zone record');

        $rrset = $response->json('rrset');
        if (! is_array($rrset) || $rrset === []) {
            throw new \RuntimeException('Hetzner API did not return an RRset.');
        }

        return $rrset;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getZoneRrset(string $zoneName, string $recordName, string $type): ?array
    {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $response = $this->request('get', "/zones/{$zoneKey}/rrsets/{$rrPath}");

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccess($response, 'get zone record');

        $rrset = $response->json('rrset');

        return is_array($rrset) ? $rrset : null;
    }

    public function deleteZoneRrset(string $zoneName, string $recordName, string $type): void
    {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $response = $this->request('delete', "/zones/{$zoneKey}/rrsets/{$rrPath}");
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete zone record');
    }

    /**
     * Map dply relative record names to Hetzner RRset names (@ for apex).
     */
    public static function normalizeRrsetName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || $recordName === $zoneName) {
            return '@';
        }

        if (str_ends_with($recordName, '.'.$zoneName)) {
            $recordName = substr($recordName, 0, -1 * (strlen($zoneName) + 1));
        }

        return $recordName !== '' ? $recordName : '@';
    }

    /**
     * Find a Cloud Firewall by exact name. Hetzner permits duplicate names, so
     * this returns the first exact match — fine for our per-server naming
     * (`dply-<server id>`), and makes provision-job retries idempotent.
     *
     * @return array<string,mixed>|null
     */
    public function findFirewallByName(string $name): ?array
    {
        $response = $this->request('get', '/firewalls', ['name' => $name]);
        $this->assertSuccess($response, 'list firewalls');

        foreach (($response->json('firewalls') ?? []) as $firewall) {
            if (($firewall['name'] ?? null) === $name) {
                return $firewall;
            }
        }

        return null;
    }

    /**
     * Create a Cloud Firewall with the given inbound rules. Returns its id.
     *
     * @param  list<array<string,mixed>>  $rules
     */
    public function createFirewall(string $name, array $rules): int
    {
        $response = $this->request('post', '/firewalls', [
            'name' => $name,
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'create firewall');

        $id = $response->json('firewall.id');
        if ($id === null) {
            throw new \RuntimeException('Hetzner API did not return firewall id.');
        }

        return (int) $id;
    }

    /**
     * Replace the rule set on an existing firewall (idempotent on retries).
     *
     * @param  list<array<string,mixed>>  $rules
     */
    public function setFirewallRules(int $firewallId, array $rules): void
    {
        $response = $this->request('post', "/firewalls/{$firewallId}/actions/set_rules", [
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'set firewall rules');
    }

    /**
     * Attach a firewall to an existing server. New servers attach atomically via
     * createInstance(firewallIds:) instead; this covers after-the-fact backfill.
     */
    public function applyFirewallToServer(int $firewallId, int $serverId): void
    {
        $response = $this->request('post', "/firewalls/{$firewallId}/actions/apply_to_resources", [
            'apply_to' => [[
                'type' => 'server',
                'server' => ['id' => $serverId],
            ]],
        ]);
        $this->assertSuccess($response, 'apply firewall to server');
    }

    /**
     * Delete a Cloud Firewall. A 404 (already gone) is treated as success.
     */
    public function deleteFirewall(int $firewallId): void
    {
        $response = $this->request('delete', "/firewalls/{$firewallId}");
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'delete firewall');
    }

    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json');

        if (strtolower($method) === 'get' && $body !== []) {
            return $request->get($url, $body);
        }
        if (strtolower($method) === 'get') {
            return $request->get($url);
        }
        if (strtolower($method) === 'post') {
            return $request->post($url, $body);
        }
        if (strtolower($method) === 'delete') {
            return $request->delete($url);
        }

        throw new \InvalidArgumentException("Unsupported method: {$method}");
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message') ?? $response->body() ?? $response->reason();
        throw new \RuntimeException("Hetzner API failed to {$action}: {$message}");
    }
}
