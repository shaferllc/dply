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
        ?string $zoneName = null,
    ): Collection {
        if ($hostnames === [] || ! $this->canCollectAnalytics()) {
            return collect();
        }

        $zoneName = strtolower(trim($zoneName ?? (string) config('edge.cloudflare.worker_zone_name')));
        if ($zoneName === '') {
            throw new RuntimeException('Could not resolve Cloudflare zone for Edge analytics.');
        }

        $zoneId = $this->resolveZoneId($zoneName);
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
                count
                dimensions { clientRequestHTTPHost }
                sum { edgeResponseBytes }
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

            $requests = (int) data_get($group, 'count', 0);
            $bytes = (int) data_get($group, 'sum.edgeResponseBytes', 0);

            $existing = $totals->get($host, new EdgeUsageTotals);
            $totals->put($host, $existing->add(new EdgeUsageTotals(
                requests: $requests,
                bytesEgress: $bytes,
            )));
        }

        return $totals;
    }

    public function canQueryAnalyticsEngine(): bool
    {
        return $this->accountId !== ''
            && $this->apiToken !== ''
            && trim((string) config('edge.cloudflare.analytics_dataset', '')) !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queryAnalyticsEngineSql(string $sql): array
    {
        if (! $this->canQueryAnalyticsEngine()) {
            return [];
        }

        $response = Http::withToken($this->apiToken)
            ->withHeaders(['Content-Type' => 'text/plain'])
            ->withBody($sql, 'text/plain')
            ->post(self::BASE.'/accounts/'.$this->accountId.'/analytics_engine/sql');

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            $message = is_array($json['errors'][0] ?? null)
                ? (string) ($json['errors'][0]['message'] ?? 'Analytics Engine SQL failed.')
                : 'Analytics Engine SQL failed.';

            throw new RuntimeException($message);
        }

        $rows = $json['result']['data'] ?? $json['result'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $meta = $json['result']['meta'] ?? [];
        if (is_array($meta) && $meta !== [] && isset($rows[0]) && is_array($rows[0]) && ! array_is_list($rows[0])) {
            return array_values(array_filter($rows, is_array(...)));
        }

        if (! is_array($meta) || $meta === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
            return [];
        }

        $columns = array_map(
            static fn ($column): string => is_array($column) ? (string) ($column['name'] ?? '') : '',
            $meta,
        );

        $mapped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $assoc = [];
            foreach ($columns as $index => $column) {
                if ($column === '') {
                    continue;
                }
                $assoc[$column] = $row[$index] ?? null;
            }

            $mapped[] = $assoc;
        }

        return $mapped;
    }

    /**
     * @return array{id: string, enabled: bool}
     */
    public function ensureLogpushJob(string $zoneId, string $destinationConf, string $dataset = 'http_requests'): array
    {
        foreach ($this->listLogpushJobs($zoneId) as $job) {
            if (($job['dataset'] ?? null) === $dataset && ($job['enabled'] ?? false) === true) {
                return [
                    'id' => (string) ($job['id'] ?? ''),
                    'enabled' => true,
                ];
            }
        }

        $payload = $this->decode(
            Http::withToken($this->apiToken)->post(self::BASE.'/zones/'.$zoneId.'/logpush/jobs', [
                'name' => 'dply-edge-'.$dataset,
                'destination_conf' => $destinationConf,
                'dataset' => $dataset,
                'enabled' => true,
            ]),
        );

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'enabled' => (bool) ($payload['enabled'] ?? true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLogpushJobs(string $zoneId): array
    {
        $response = Http::withToken($this->apiToken)->get(self::BASE.'/zones/'.$zoneId.'/logpush/jobs');
        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            return [];
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    public function fetchR2BucketUsage(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $bucketName = null,
    ): EdgeUsageTotals {
        $bucketName = trim($bucketName ?? (string) config('edge.r2.bucket', ''));
        if ($bucketName === '' || $this->accountId === '') {
            return EdgeUsageTotals::empty();
        }

        $query = <<<'GRAPHQL'
        query EdgeR2Usage($accountTag: string!, $since: Time!, $until: Time!, $bucket: string!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              r2StorageAdaptiveGroups(
                limit: 100
                filter: { datetime_geq: $since, datetime_leq: $until, bucketName: $bucket }
              ) {
                max { storedBytes }
              }
              r2OperationsAdaptiveGroups(
                limit: 1000
                filter: { datetime_geq: $since, datetime_leq: $until, bucketName: $bucket }
              ) {
                sum { requests }
                dimensions { actionType }
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($this->apiToken)
            ->post(self::BASE.'/graphql', [
                'query' => $query,
                'variables' => [
                    'accountTag' => $this->accountId,
                    'since' => $periodStart->toIso8601String(),
                    'until' => $periodEnd->toIso8601String(),
                    'bucket' => $bucketName,
                ],
            ]);

        $json = $response->json();
        if (! is_array($json) || ! empty($json['errors'])) {
            throw new RuntimeException('Cloudflare R2 GraphQL request failed.');
        }

        $storageGroups = data_get($json, 'data.viewer.accounts.0.r2StorageAdaptiveGroups', []);
        $operationGroups = data_get($json, 'data.viewer.accounts.0.r2OperationsAdaptiveGroups', []);

        $storedBytes = 0;
        if (is_array($storageGroups)) {
            foreach ($storageGroups as $group) {
                $storedBytes = max($storedBytes, (int) data_get($group, 'max.storedBytes', 0));
            }
        }

        $classA = 0;
        $classB = 0;
        $classAActions = [
            'PutObject', 'CopyObject', 'ListObjects', 'CreateMultipartUpload',
            'UploadPart', 'CompleteMultipartUpload', 'DeleteObject', 'AbortMultipartUpload',
        ];

        if (is_array($operationGroups)) {
            foreach ($operationGroups as $group) {
                $action = (string) data_get($group, 'dimensions.actionType', '');
                $requests = (int) data_get($group, 'sum.requests', 0);
                if (in_array($action, $classAActions, true)) {
                    $classA += $requests;
                } elseif (in_array($action, ['GetObject', 'HeadObject'], true)) {
                    $classB += $requests;
                }
            }
        }

        return new EdgeUsageTotals(
            r2StorageBytes: $storedBytes,
            r2ClassAOps: $classA,
            r2ClassBOps: $classB,
        );
    }

    public function activeZoneId(string $zoneName): ?string
    {
        return $this->resolveZoneId($zoneName);
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
