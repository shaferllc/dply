<?php

namespace App\Services\Cloudflare;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare DNS API (API token auth). Used for site DNS automation when zone lives in Cloudflare.
 *
 * @see https://developers.cloudflare.com/api/
 */
class CloudflareDnsService
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    private string $bearerToken;

    /**
     * @param  ProviderCredential|non-empty-string  $credentialOrToken
     */
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

    public function verifyToken(): void
    {
        $response = $this->request('get', '/user/tokens/verify');
        $this->assertApiSuccess($response, 'verify Cloudflare token');
    }

    public function zoneExists(string $zoneName): bool
    {
        return $this->findZoneId($zoneName) !== null;
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
     * @return array<string, mixed>
     */
    public function upsertARecord(string $zoneName, string $relativeRecordName, string $ipv4): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            throw new \RuntimeException('Zone not found in this Cloudflare account.');
        }

        $fqdn = $this->fqdn($zoneName, $relativeRecordName);
        $existing = $this->findARecord($zoneId, $fqdn);

        if ($existing !== null) {
            $recordId = (string) ($existing['id'] ?? '');
            if ($recordId === '') {
                throw new \RuntimeException('Cloudflare returned an A record without an id.');
            }

            $response = $this->request('put', '/zones/'.$zoneId.'/dns_records/'.$recordId, [
                'type' => 'A',
                'name' => $fqdn,
                'content' => $ipv4,
                'ttl' => 120,
                'proxied' => false,
            ]);
            $this->assertApiSuccess($response, 'update Cloudflare DNS record');
            $result = $response->json('result');

            return is_array($result) ? $result : [];
        }

        $response = $this->request('post', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => $fqdn,
            'content' => $ipv4,
            'ttl' => 120,
            'proxied' => false,
        ]);
        $this->assertApiSuccess($response, 'create Cloudflare DNS record');
        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }

    public function deleteDnsRecord(string $zoneName, string $recordId): void
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            return;
        }

        if ($recordId === '') {
            return;
        }

        $response = $this->request('delete', '/zones/'.$zoneId.'/dns_records/'.$recordId);
        if ($response->status() === 404) {
            return;
        }
        $this->assertApiSuccess($response, 'delete Cloudflare DNS record');
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

    private function fqdn(string $zone, string $relativeName): string
    {
        $zone = strtolower(trim($zone));
        $relativeName = trim($relativeName);
        $lower = strtolower($relativeName);
        if ($lower === '') {
            return $zone;
        }
        if (str_ends_with($lower, '.'.$zone)) {
            return $lower;
        }

        return $lower.'.'.$zone;
    }

    private function request(string $method, string $path, array $queryOrBody = []): Response
    {
        $url = self::BASE.$path;
        $client = Http::withToken($this->bearerToken)->acceptJson();

        return match (strtolower($method)) {
            'get' => $client->get($url, $queryOrBody),
            'post' => $client->asJson()->post($url, $queryOrBody),
            'put' => $client->asJson()->put($url, $queryOrBody),
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
