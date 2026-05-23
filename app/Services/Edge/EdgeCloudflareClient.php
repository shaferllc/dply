<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Services\Billing\EdgeUsageTotals;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Cloudflare API client for dply Edge platform provisioning.
 */
class EdgeCloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('edge.cloudflare.account_id'),
            (string) config('edge.cloudflare.api_token'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyToken(): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)->get(self::BASE.'/user/tokens/verify'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccounts(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)->get(self::BASE.'/accounts'),
        );

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listR2Buckets(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/r2/buckets'),
        );

        return is_array($payload['buckets'] ?? null) ? $payload['buckets'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createR2Bucket(string $name): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/r2/buckets', [
                    'name' => $name,
                ]),
        );
    }

    public function r2BucketExists(string $name): bool
    {
        foreach ($this->listR2Buckets() as $bucket) {
            if (($bucket['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listKvNamespaces(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/storage/kv/namespaces'),
        );

        return is_array($payload['result'] ?? null) ? $payload['result'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createKvNamespace(string $title): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/storage/kv/namespaces', [
                    'title' => $title,
                ]),
        );
    }

    public function kvNamespaceIdByTitle(string $title): ?string
    {
        foreach ($this->listKvNamespaces() as $namespace) {
            if (($namespace['title'] ?? null) === $title && is_string($namespace['id'] ?? null)) {
                return $namespace['id'];
            }
        }

        return null;
    }

    public function canCollectAnalytics(): bool
    {
        return $this->accountId !== ''
            && $this->apiToken !== ''
            && is_string(config('edge.cloudflare.worker_zone_name'))
            && config('edge.cloudflare.worker_zone_name') !== '';
    }

    /**
     * Pull zone HTTP request + bandwidth totals grouped by client request host.
     *
     * Uses Cloudflare GraphQL httpRequestsAdaptiveGroups. Requires Analytics
     * read on the API token and a resolvable zone for worker_zone_name.
     *
     * @param  list<string>  $hostnames
     * @return Collection<string, EdgeUsageTotals>
     */
    public function fetchHttpUsageByHostnames(
        array $hostnames,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): Collection {
        if ($hostnames === [] || ! $this->canCollectAnalytics()) {
            return collect();
        }

        $zoneId = $this->resolveZoneId((string) config('edge.cloudflare.worker_zone_name'));
        if ($zoneId === null) {
            throw new RuntimeException('Could not resolve Cloudflare zone for Edge analytics.');
        }

        $query = <<<'GRAPHQL'
        query EdgeHttpUsage($zoneTag: string!, $since: Time!, $until: Time!) {
          viewer {
            zones(filter: { zoneTag: $zoneTag }) {
              httpRequestsAdaptiveGroups(
                limit: 10000
                filter: { datetime_geq: $since, datetime_leq: $until }
                orderBy: [count_DESC]
              ) {
                dimensions { clientRequestHTTPHost }
                sum { requests edgeResponseBytes }
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($this->apiToken)
            ->post(self::BASE.'/graphql', [
                'query' => $query,
                'variables' => [
                    'zoneTag' => $zoneId,
                    'since' => $periodStart->toIso8601String(),
                    'until' => $periodEnd->toIso8601String(),
                ],
            ]);

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Cloudflare GraphQL returned a non-JSON response.');
        }

        if (! empty($json['errors'])) {
            $message = is_array($json['errors'][0] ?? null)
                ? (string) ($json['errors'][0]['message'] ?? 'Cloudflare GraphQL request failed.')
                : 'Cloudflare GraphQL request failed.';

            throw new RuntimeException($message);
        }

        $groups = data_get($json, 'data.viewer.zones.0.httpRequestsAdaptiveGroups', []);
        if (! is_array($groups)) {
            return collect();
        }

        $normalizedHosts = array_fill_keys(
            array_map(static fn (string $host): string => strtolower($host), $hostnames),
            true,
        );

        /** @var Collection<string, EdgeUsageTotals> $totals */
        $totals = collect();

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $host = strtolower(trim((string) data_get($group, 'dimensions.clientRequestHTTPHost', '')));
            if ($host === '' || ! isset($normalizedHosts[$host])) {
                continue;
            }

            $requests = (int) data_get($group, 'sum.requests', 0);
            $bytes = (int) data_get($group, 'sum.edgeResponseBytes', 0);

            $existing = $totals->get($host, new EdgeUsageTotals);
            $totals->put($host, $existing->add(new EdgeUsageTotals(
                requests: $requests,
                bytesEgress: $bytes,
            )));
        }

        return $totals;
    }

    private function resolveZoneId(string $zoneName): ?string
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = Http::withToken($this->apiToken)->get(self::BASE.'/zones', [
            'name' => $zoneName,
            'status' => 'active',
            'per_page' => 1,
        ]);

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            return null;
        }

        $result = $json['result'] ?? [];
        if (! is_array($result) || ! isset($result[0]['id'])) {
            return null;
        }

        return (string) $result[0]['id'];
    }

    /**
     * MVP stub for Cloudflare Custom Hostnames (SSL for SaaS) — full provisioning deferred to Phase 3b.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function createCustomHostname(string $zoneId, string $hostname, array $options = []): array
    {
        $response = Http::withToken($this->apiToken)
            ->post(self::BASE.'/zones/'.$zoneId.'/custom_hostnames', array_merge([
                'hostname' => strtolower(trim($hostname)),
                'ssl' => [
                    'method' => 'http',
                    'type' => 'dv',
                ],
            ], $options));

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Cloudflare API returned a non-JSON response.');
        }

        if (($json['success'] ?? false) !== true) {
            $errors = $json['errors'] ?? [];
            $message = is_array($errors) && isset($errors[0]['message'])
                ? (string) $errors[0]['message']
                : 'Cloudflare API request failed.';

            throw new RuntimeException($message);
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }
}
