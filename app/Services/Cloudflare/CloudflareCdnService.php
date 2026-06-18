<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare zone-level edge ops: flip the orange-cloud proxy on/off for a
 * site's hostname, adjust cache aggressiveness, and purge by hostname.
 *
 * Distinct from {@see CloudflareDnsService} (which manages unproxied A records
 * for site DNS automation). This one is the "Edge in front" feature's API
 * surface — it manages the proxied=true variant and the cache_level/browser
 * TTL zone settings that come with it.
 *
 * @see https://developers.cloudflare.com/api/
 */
class CloudflareCdnService
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public const PRESET_STANDARD = 'standard';

    public const PRESET_AGGRESSIVE = 'aggressive';

    public const PRESET_BYPASS = 'bypass';

    private string $bearerToken;

    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('Cloudflare API token is required.');
        }
        $this->bearerToken = $token;
    }

    public function findZoneId(string $zoneName): ?string
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = $this->request('get', '/zones', [
            'name' => $zoneName,
            'status' => 'active',
        ]);
        $this->assertApiSuccess($response, 'list Cloudflare zones');
        $results = $response->json('result');
        if (! is_array($results) || $results === []) {
            return null;
        }

        $first = $results[0];
        $id = is_array($first) ? ($first['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Upsert a proxied A record so traffic for $fqdn flows through Cloudflare.
     * Returns the record id so callers can persist it for later teardown.
     */
    public function enableProxyForRecord(string $zoneId, string $fqdn, string $originIp): string
    {
        $existing = $this->findARecord($zoneId, $fqdn);

        if ($existing !== null) {
            $recordId = (string) ($existing['id'] ?? '');
            if ($recordId === '') {
                throw new \RuntimeException('Cloudflare returned an A record without an id.');
            }

            $response = $this->request('put', '/zones/'.$zoneId.'/dns_records/'.$recordId, [
                'type' => 'A',
                'name' => strtolower($fqdn),
                'content' => $originIp,
                'ttl' => 1, // 1 = "automatic" when proxied
                'proxied' => true,
            ]);
            $this->assertApiSuccess($response, 'update Cloudflare A record');

            return $recordId;
        }

        $response = $this->request('post', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => strtolower($fqdn),
            'content' => $originIp,
            'ttl' => 1,
            'proxied' => true,
        ]);
        $this->assertApiSuccess($response, 'create Cloudflare A record');

        $id = $response->json('result.id');
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('Cloudflare create response missing record id.');
        }

        return $id;
    }

    /**
     * Flip an existing managed record back to unproxied (grey-cloud). Leaves
     * the record itself in place so origin DNS keeps resolving — this is
     * "stop edging this site", not "delete the hostname".
     */
    public function disableProxyForRecord(string $zoneId, string $recordId, string $fqdn, string $originIp): void
    {
        if ($recordId === '') {
            return;
        }

        $response = $this->request('put', '/zones/'.$zoneId.'/dns_records/'.$recordId, [
            'type' => 'A',
            'name' => strtolower($fqdn),
            'content' => $originIp,
            'ttl' => 120,
            'proxied' => false,
        ]);
        if ($response->status() === 404) {
            return;
        }
        $this->assertApiSuccess($response, 'disable Cloudflare proxy');
    }

    public function applyCachePreset(string $zoneId, string $preset): void
    {
        [$cacheLevel, $browserTtl] = match ($preset) {
            self::PRESET_AGGRESSIVE => ['aggressive', 14400],
            self::PRESET_BYPASS => ['bypass', 0],
            default => ['standard', 1800],
        };

        $this->patchZoneSetting($zoneId, 'cache_level', $cacheLevel);
        $this->patchZoneSetting($zoneId, 'browser_cache_ttl', $browserTtl);
    }

    /**
     * Purge cached content for a single hostname. Cloudflare's `purge_cache`
     * endpoint accepts a `hosts` array — narrower than a full purge so we
     * don't blow away neighboring sites that share the same zone.
     */
    public function purgeHostname(string $zoneId, string $hostname): void
    {
        $response = $this->request('post', '/zones/'.$zoneId.'/purge_cache', [
            'hosts' => [strtolower($hostname)],
        ]);
        $this->assertApiSuccess($response, 'purge Cloudflare cache');
    }

    public const RULE_ACTION_BYPASS = 'bypass';

    public const RULE_ACTION_CACHE = 'cache';

    /**
     * Reconcile dply-managed cache rules in the zone's
     * `http_request_cache_settings` entrypoint ruleset. User-managed rules
     * (any rule whose `description` does not start with `$managedPrefix`)
     * are preserved verbatim; ours are stripped and re-appended from
     * `$rules` so each save is a full overwrite of dply's slice.
     *
     * @param  array<string, mixed> $rules
     */
    public function syncCacheRules(string $zoneId, string $hostname, array $rules, string $managedPrefix): void
    {
        $existing = $this->fetchCachePhaseRules($zoneId);
        $preserved = array_values(array_filter(
            $existing,
            fn (array $r): bool => ! str_starts_with((string) ($r['description'] ?? ''), $managedPrefix),
        ));

        $managed = [];
        foreach (array_values($rules) as $i => $rule) {
            $managed[] = $this->buildCacheRule($hostname, $rule, $managedPrefix.':'.$i);
        }

        $this->putCachePhaseRules($zoneId, array_merge($preserved, $managed));
    }

    public function clearManagedCacheRules(string $zoneId, string $managedPrefix): void
    {
        $existing = $this->fetchCachePhaseRules($zoneId);
        $preserved = array_values(array_filter(
            $existing,
            fn (array $r): bool => ! str_starts_with((string) ($r['description'] ?? ''), $managedPrefix),
        ));

        if (count($preserved) === count($existing)) {
            return; // nothing of ours present.
        }

        $this->putCachePhaseRules($zoneId, $preserved);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCachePhaseRules(string $zoneId): array
    {
        $response = $this->request('get', '/zones/'.$zoneId.'/rulesets/phases/http_request_cache_settings/entrypoint');
        if ($response->status() === 404) {
            return [];
        }
        $this->assertApiSuccess($response, 'fetch Cloudflare cache ruleset');

        $rules = $response->json('result.rules');

        return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function putCachePhaseRules(string $zoneId, array $rules): void
    {
        $response = $this->request(
            'put',
            '/zones/'.$zoneId.'/rulesets/phases/http_request_cache_settings/entrypoint',
            ['rules' => $rules],
        );
        $this->assertApiSuccess($response, 'update Cloudflare cache ruleset');
    }

    /**
     * @param  array{path: string, action: string, ttl?: int}  $rule
     * @return array<string, mixed>
     */
    private function buildCacheRule(string $hostname, array $rule, string $description): array
    {
        $path = (string) $rule['path'];
        $expression = sprintf(
            '(http.host eq "%s" and starts_with(http.request.uri.path, "%s"))',
            $this->escapeWirefilter(strtolower($hostname)),
            $this->escapeWirefilter($path),
        );

        $params = $rule['action'] === self::RULE_ACTION_BYPASS
            ? ['cache' => false]
            : [
                'cache' => true,
                'edge_ttl' => [
                    'mode' => 'override_origin',
                    'default' => max(1, (int) ($rule['ttl'] ?? 3600)),
                ],
            ];

        return [
            'description' => $description,
            'expression' => $expression,
            'action' => 'set_cache_settings',
            'action_parameters' => $params,
            'enabled' => true,
        ];
    }

    private function escapeWirefilter(string $value): string
    {
        // Wirefilter string literals use backslash-escaped double quotes.
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Fetch zone-level analytics totals over the trailing `sinceMinutes`
     * window. Returns a flat snapshot (totals only, no timeseries) so the
     * caller can persist it without parsing a nested payload.
     *
     * Uses Cloudflare's GraphQL Analytics API — the legacy
     * `/analytics/dashboard` REST endpoint is being sunset. We sum the
     * hourly `httpRequests1hGroups` buckets to get a window total; the
     * GraphQL schema returns hourly granularity for windows ≥ 1h and the
     * caller is responsible for clamping `sinceMinutes` accordingly
     * (the default of 1440 ⇒ 24 buckets).
     *
     * @return array{requests_all: int, requests_cached: int, bandwidth_all: int, bandwidth_cached: int, since_minutes: int}
     */
    /** @return array<string, mixed> */
    public function fetchDashboardAnalytics(string $zoneId, int $sinceMinutes = 1440): array
    {
        // Cloudflare's GraphQL Analytics expects ISO-8601 timestamps; align
        // the window to the previous full hour so the same query keeps
        // returning the same answer when re-run within the same minute.
        $until = now()->startOfHour();
        $since = $until->copy()->subMinutes($sinceMinutes);

        $query = <<<'GQL'
        query($zoneTag: string!, $since: Time!, $until: Time!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              httpRequests1hGroups(
                limit: 10000,
                filter: {datetime_geq: $since, datetime_lt: $until}
              ) {
                sum {
                  requests
                  cachedRequests
                  bytes
                  cachedBytes
                }
              }
            }
          }
        }
        GQL;

        $response = $this->request('post', '/graphql', [
            'query' => $query,
            'variables' => [
                'zoneTag' => $zoneId,
                'since' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
            ],
        ]);
        if (! $response->successful()) {
            $message = $response->json('errors.0.message')
                ?? $response->body()
                ?: $response->reason();

            throw new \RuntimeException("Failed to fetch Cloudflare analytics: {$message}");
        }

        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            $first = $errors[0] ?? [];
            $msg = is_array($first) ? ($first['message'] ?? json_encode($errors)) : json_encode($errors);

            throw new \RuntimeException("Failed to fetch Cloudflare analytics: {$msg}");
        }

        $zones = $response->json('data.viewer.zones');
        $groups = is_array($zones) && isset($zones[0]['httpRequests1hGroups']) && is_array($zones[0]['httpRequests1hGroups'])
            ? $zones[0]['httpRequests1hGroups']
            : [];

        $totals = ['requests' => 0, 'cachedRequests' => 0, 'bytes' => 0, 'cachedBytes' => 0];
        foreach ($groups as $group) {
            $sum = is_array($group) && is_array($group['sum'] ?? null) ? $group['sum'] : [];
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($sum[$key] ?? 0);
            }
        }

        return [
            'requests_all' => $totals['requests'],
            'requests_cached' => $totals['cachedRequests'],
            'bandwidth_all' => $totals['bytes'],
            'bandwidth_cached' => $totals['cachedBytes'],
            'since_minutes' => $sinceMinutes,
        ];
    }

    private function patchZoneSetting(string $zoneId, string $key, mixed $value): void
    {
        $response = $this->request('patch', '/zones/'.$zoneId.'/settings/'.$key, [
            'value' => $value,
        ]);
        $this->assertApiSuccess($response, "set Cloudflare zone setting {$key}");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findARecord(string $zoneId, string $fqdn): ?array
    {
        $response = $this->request('get', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => strtolower($fqdn),
        ]);
        $this->assertApiSuccess($response, 'list Cloudflare DNS records');
        $results = $response->json('result');
        if (! is_array($results) || $results === []) {
            return null;
        }

        foreach ($results as $row) {
            if (is_array($row) && strtoupper((string) ($row['type'] ?? '')) === 'A') {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $queryOrBody
     */
    private function request(string $method, string $path, array $queryOrBody = []): Response
    {
        $url = self::BASE.$path;
        $client = Http::withToken($this->bearerToken)->acceptJson();

        return match (strtolower($method)) {
            'get' => $client->get($url, $queryOrBody),
            'post' => $client->asJson()->post($url, $queryOrBody),
            'put' => $client->asJson()->put($url, $queryOrBody),
            'patch' => $client->asJson()->patch($url, $queryOrBody),
            'delete' => $client->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }

    private function assertApiSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            $json = $response->json();
            if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
                $errors = $json['errors'] ?? [];
                $msg = is_array($errors) && $errors !== [] ? json_encode($errors) : $response->body();

                throw new \RuntimeException("Failed to {$action}: {$msg}");
            }

            return;
        }

        $message = $response->json('errors.0.message')
            ?? $response->json('message')
            ?? $response->body()
            ?: $response->reason();

        throw new \RuntimeException("Failed to {$action}: {$message}");
    }
}
