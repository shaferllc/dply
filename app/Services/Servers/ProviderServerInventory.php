<?php

namespace App\Services\Servers;

use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Cloud\Services\HetznerService;
use App\Modules\Cloud\Services\LinodeService;
use App\Modules\Cloud\Services\VultrService;

/**
 * Reads a provider account's existing machines and normalises them into one
 * shape, so the create wizard's scan-and-import mode can offer "servers you
 * own but dply doesn't manage yet" without knowing each API's vocabulary.
 *
 * Every row is:
 *   provider_id  string  the provider's own id — what Server.provider_id stores
 *   name         string
 *   public_ipv4  ?string
 *   private_ipv4 ?string
 *   region       ?string  slug (nyc3, fsn1, …)
 *   size         ?string  plan/type slug
 *   status       ?string  provider-native status word
 *   imported     bool     already a Server row in this organization
 */
class ProviderServerInventory
{
    /** Providers whose API can enumerate instances. */
    public const SUPPORTED = [
        ServerProvider::DigitalOcean->value,
        ServerProvider::Hetzner->value,
        ServerProvider::Linode->value,
        ServerProvider::Vultr->value,
    ];

    public function supports(?string $provider): bool
    {
        return $provider !== null && in_array($provider, self::SUPPORTED, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException when the provider isn't scannable
     * @throws \Throwable on any API failure — the caller renders the message
     */
    public function list(ProviderCredential $credential): array
    {
        $provider = (string) $credential->provider;

        if (! $this->supports($provider)) {
            throw new \RuntimeException("Scanning is not supported for {$provider}.");
        }

        $rows = match ($provider) {
            ServerProvider::DigitalOcean->value => $this->digitalOcean($credential),
            ServerProvider::Hetzner->value => $this->hetzner($credential),
            ServerProvider::Linode->value => $this->linode($credential),
            ServerProvider::Vultr->value => $this->vultr($credential),
            default => [],
        };

        return $this->markImported($rows, $credential, $provider);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function markImported(array $rows, ProviderCredential $credential, string $provider): array
    {
        // Match on provider_id within the org — the same machine adopted under
        // a second credential is still the same machine.
        $servers = Server::query()
            ->where('organization_id', $credential->organization_id)
            ->where('provider', $provider)
            ->get(['id', 'name', 'provider_id', 'ip_address']);

        $byId = $servers->filter(fn (Server $s): bool => filled($s->provider_id))
            ->keyBy(fn (Server $s): string => (string) $s->provider_id);

        // …and by address, because a server added before dply recorded
        // provider_ids has none. Without this it reads as "not in dply" and a
        // second import would duplicate a host that is already managed.
        $byIp = $servers->filter(fn (Server $s): bool => filled($s->ip_address))
            ->keyBy(fn (Server $s): string => (string) $s->ip_address);

        return array_map(function (array $row) use ($byId, $byIp): array {
            $match = $byId->get((string) $row['provider_id'])
                ?? ($row['public_ipv4'] !== null ? $byIp->get((string) $row['public_ipv4']) : null);

            // Carrying the matched server through is what lets the caller ask
            // the question that actually matters — not "is it in dply?" but
            // "can dply reach it?" — using that server's stored credentials.
            return $row + [
                'imported' => $match !== null,
                'server_id' => $match?->id,
                'server_name' => $match?->name,
            ];
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function digitalOcean(ProviderCredential $credential): array
    {
        $service = new DigitalOceanService($credential);

        return array_map(fn (array $d): array => [
            'provider_id' => (string) ($d['id'] ?? ''),
            'name' => (string) ($d['name'] ?? ''),
            'public_ipv4' => DigitalOceanService::getDropletPublicIp($d),
            'private_ipv4' => DigitalOceanService::getDropletPrivateIp($d),
            'region' => $this->str($d['region']['slug'] ?? null),
            'size' => $this->str($d['size_slug'] ?? null),
            'status' => $this->str($d['status'] ?? null),
        ], $service->getDroplets());
    }

    /** @return array<int, array<string, mixed>> */
    private function hetzner(ProviderCredential $credential): array
    {
        $service = new HetznerService($credential);

        return array_map(fn (array $s): array => [
            'provider_id' => (string) ($s['id'] ?? ''),
            'name' => (string) ($s['name'] ?? ''),
            'public_ipv4' => HetznerService::getPublicIp($s),
            'private_ipv4' => HetznerService::getPrivateIp($s),
            'region' => $this->str($s['datacenter']['location']['name'] ?? null),
            'size' => $this->str($s['server_type']['name'] ?? null),
            'status' => $this->str($s['status'] ?? null),
        ], $service->listInstances());
    }

    /** @return array<int, array<string, mixed>> */
    private function linode(ProviderCredential $credential): array
    {
        $service = new LinodeService($credential);

        return array_map(fn (array $i): array => [
            'provider_id' => (string) ($i['id'] ?? ''),
            'name' => (string) ($i['label'] ?? ''),
            'public_ipv4' => LinodeService::getPublicIp($i),
            'private_ipv4' => $this->linodePrivateIp($i),
            'region' => $this->str($i['region'] ?? null),
            'size' => $this->str($i['type'] ?? null),
            'status' => $this->str($i['status'] ?? null),
        ], $service->listInstances());
    }

    /** @return array<int, array<string, mixed>> */
    private function vultr(ProviderCredential $credential): array
    {
        $service = new VultrService($credential);

        return array_map(fn (array $i): array => [
            'provider_id' => (string) ($i['id'] ?? ''),
            'name' => (string) ($i['label'] ?? $i['hostname'] ?? ''),
            'public_ipv4' => VultrService::getPublicIp($i),
            'private_ipv4' => VultrService::getPrivateIp($i),
            'region' => $this->str($i['region'] ?? null),
            'size' => $this->str($i['plan'] ?? null),
            'status' => $this->str($i['status'] ?? null),
        ], $service->listInstances());
    }

    /**
     * Linode returns every address in one `ipv4` list; the private ones are the
     * RFC1918 members (192.168.x is Linode's private range).
     *
     * @param  array<string, mixed>  $instance
     */
    private function linodePrivateIp(array $instance): ?string
    {
        foreach ((array) ($instance['ipv4'] ?? []) as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                return $ip;
            }
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_int($value) ? (string) $value : null);
    }
}
