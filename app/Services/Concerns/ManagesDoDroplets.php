<?php

declare(strict_types=1);

namespace App\Services\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoDroplets
{


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
     * Get private IPv4 from a droplet array (VPC / private network interface).
     */
    public static function getDropletPrivateIp(array $droplet): ?string
    {
        $networks = $droplet['networks'] ?? [];
        if (isset($networks['v4']) && is_array($networks['v4'])) {
            foreach ($networks['v4'] as $n) {
                if (($n['type'] ?? '') === 'private') {
                    $ip = $n['ip_address'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The UUID of the VPC the droplet is attached to. Every droplet belongs to a
     * VPC — the region's default one when none is specified at create — so this
     * is the stable identity for recording the droplet's private network.
     */
    public static function getDropletVpcUuid(array $droplet): ?string
    {
        $uuid = $droplet['vpc_uuid'] ?? null;

        return is_string($uuid) && trim($uuid) !== '' ? trim($uuid) : null;
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
}
