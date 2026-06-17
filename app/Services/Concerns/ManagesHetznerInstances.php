<?php

declare(strict_types=1);

namespace App\Services\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesHetznerInstances
{


    /**
     * Register an SSH public key in the Hetzner project. Returns key array with id.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
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
     * @param  array<string, mixed> $sshKeyIds  Hetzner SSH key IDs or names
     */
    /**
     * @param  array<string, mixed> $sshKeyIds  Hetzner SSH key IDs or names
     * @param  array<string, mixed> $firewallIds  Cloud Firewall IDs to attach at boot (atomic — no unreachable window)
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
    /** @return array<string, mixed> */
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
     * Destroy (delete) an instance by ID.
     */
    public function destroyInstance(int $id): void
    {
        $response = $this->request('delete', "/servers/{$id}");
        $this->assertSuccess($response, 'delete server');
    }

    /**
     * Power a server off (hard stop). Returns the action object so the caller
     * can poll {@see waitForAction}. A powered-off server yields a
     * crash-consistent snapshot.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
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
     * @param  array<string, mixed> $labels
     * @return array{action: array<string, mixed>, image_id: int}
     */
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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

    /**
     * List locations (for region dropdown).
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
}
