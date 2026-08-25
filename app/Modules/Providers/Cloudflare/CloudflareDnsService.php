<?php

namespace App\Modules\Providers\Cloudflare;

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
    /** Human label for whichever token this was built from, for error messages. */
    private string $tokenOrigin = '';

    /**
     * Set only for legacy Global API Key auth, which needs the account email
     * alongside the key. Empty means a modern API token (Bearer).
     */
    private string $authEmail = '';

    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;

        // A ProviderCredential is a CUSTOMER's connected account. Recording that
        // here is the whole point: "zone not found" against a dply-owned zone is
        // almost always a customer token being used where a platform token
        // belongs, and the old message could not tell the two apart.
        $this->tokenOrigin = $credentialOrToken instanceof ProviderCredential
            ? 'customer credential "'.$credentialOrToken->name.'" (org '.((string) $credentialOrToken->organization_id).')'
            : '';

        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('Cloudflare API token is required.');
        }
        $this->bearerToken = $token;

        // Legacy Global API Key auth, but ONLY for the platform key: a
        // customer's ProviderCredential is always a scoped API token, and
        // pairing it with dply's account email would authenticate as the wrong
        // identity. Opt-in by configuring services.cloudflare.email.
        if (! $credentialOrToken instanceof ProviderCredential) {
            $platformKey = trim((string) config('services.cloudflare.key', ''));
            $email = trim((string) config('services.cloudflare.email', ''));
            if ($email !== '' && $platformKey !== '' && hash_equals($platformKey, $token)) {
                $this->authEmail = $email;
            }
        }
    }

    /** Which Cloudflare auth scheme this instance uses. */
    public function authScheme(): string
    {
        return $this->authEmail !== '' ? 'global-api-key' : 'api-token';
    }

    /**
     * Identify the token in an error WITHOUT leaking it: a short digest plus
     * the last four characters is enough to match against a Cloudflare
     * dashboard entry, and is useless to anyone reading a log.
     */
    private function tokenFingerprint(): string
    {
        return 'sha256:'.substr(hash('sha256', $this->bearerToken), 0, 8)
            .' …'.substr($this->bearerToken, -4);
    }

    /**
     * Why a zone lookup came back empty, in terms an operator can act on.
     *
     * Names the token (by origin when known, otherwise by matching it against
     * the platform config keys) and lists what that token CAN see — the fastest
     * way to spot "this is the mail token" or "this is the customer's account".
     */
    private function zoneNotFoundMessage(string $zoneName): string
    {
        $origin = $this->tokenOrigin !== ''
            ? $this->tokenOrigin
            : \App\Support\TestingDomains::describeCloudflareToken($this->bearerToken);

        $visible = '';
        try {
            $zones = $this->listZoneNames();
            $visible = $zones === []
                ? ' That token can see NO zones at all.'
                : ' That token can see: '.implode(', ', array_slice($zones, 0, 8))
                    .(count($zones) > 8 ? ' (+'.(count($zones) - 8).' more)' : '').'.';
        } catch (\Throwable) {
            // Listing is a nicety; never let it mask the real failure.
        }

        return 'Zone ['.$zoneName.'] was not found in this Cloudflare account. '
            .'Token used: '.$origin.' ['.$this->tokenFingerprint().'].'.$visible
            .' Add the zone to that token’s Zone Resources, or point dply at a token from the account that owns it.';
    }

    public function verifyToken(): void
    {
        // Verify by listing zones rather than /user/tokens/verify: the latter only
        // validates USER-owned tokens, so an account-owned token (cfat_…) — valid
        // for DNS — fails there as "Invalid API Token". Listing zones exercises the
        // Zone:Zone:Read permission dply actually needs and works for both token
        // kinds. An empty zone list is still a valid token.
        $response = $this->request('get', '/zones', ['per_page' => 1]);
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

        $results = $this->zonesNamed($zoneName, activeOnly: true);
        if ($results === []) {
            $results = $this->zonesNamed($zoneName, activeOnly: false);
        }
        if ($results === []) {
            return null;
        }

        $id = $results[0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zonesNamed(string $zoneName, bool $activeOnly): array
    {
        $query = ['name' => $zoneName, 'per_page' => 5];
        if ($activeOnly) {
            $query['status'] = 'active';
        }

        $response = $this->request('get', '/zones', $query);
        $this->assertApiSuccess($response, 'list Cloudflare zones');
        $results = $response->json('result');

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * @return list<string>
     */
    public function listZoneNames(): array
    {
        $response = $this->request('get', '/zones', ['per_page' => 50]);
        $this->assertApiSuccess($response, 'list Cloudflare zones');
        $names = [];
        foreach ((array) $response->json('result') as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && $row['name'] !== '') {
                $names[] = strtolower($row['name']);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertARecord(string $zoneName, string $relativeRecordName, string $ipv4): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            throw new \RuntimeException($this->zoneNotFoundMessage($zoneName));
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
     * @return array<string, mixed>
     */
    public function upsertCnameRecord(string $zoneName, string $relativeRecordName, string $targetHost): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            throw new \RuntimeException($this->zoneNotFoundMessage($zoneName));
        }

        $fqdn = $this->fqdn($zoneName, $relativeRecordName);
        $target = rtrim(strtolower(trim($targetHost)), '.');
        $existing = $this->findCnameRecord($zoneName, $fqdn);

        if ($existing !== null) {
            $recordId = (string) ($existing['id'] ?? '');
            if ($recordId === '') {
                throw new \RuntimeException('Cloudflare returned a CNAME record without an id.');
            }

            $response = $this->request('put', '/zones/'.$zoneId.'/dns_records/'.$recordId, [
                'type' => 'CNAME',
                'name' => $fqdn,
                'content' => $target,
                'ttl' => 120,
                'proxied' => true,
            ]);
            $this->assertApiSuccess($response, 'update Cloudflare CNAME record');
            $result = $response->json('result');

            return is_array($result) ? $result : [];
        }

        $response = $this->request('post', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'CNAME',
            'name' => $fqdn,
            'content' => $target,
            'ttl' => 120,
            'proxied' => true,
        ]);
        $this->assertApiSuccess($response, 'create Cloudflare CNAME record');
        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCnameRecord(string $zoneName, string $fqdn): ?array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            return null;
        }

        $response = $this->request('get', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'CNAME',
            'name' => strtolower($fqdn),
        ]);
        $this->assertApiSuccess($response, 'list Cloudflare DNS records');
        $results = $response->json('result');
        if (! is_array($results) || $results === []) {
            return null;
        }

        foreach ($results as $row) {
            if (is_array($row) && strtoupper((string) ($row['type'] ?? '')) === 'CNAME') {
                return $row;
            }
        }

        return null;
    }

    /**
     * List DNS records of a given type in the zone, optionally filtered by exact
     * name. Returns the raw Cloudflare record rows (empty when the zone isn't in
     * this account). Used to pre-flight mail auth records (SPF/DKIM/DMARC).
     *
     * @return list<array<string, mixed>>
     */
    public function listDnsRecords(string $zoneName, string $type, ?string $name = null): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            return [];
        }

        $query = ['type' => strtoupper($type), 'per_page' => 100];
        if ($name !== null && $name !== '') {
            $query['name'] = strtolower($name);
        }

        $response = $this->request('get', '/zones/'.$zoneId.'/dns_records', $query);
        $this->assertApiSuccess($response, 'list Cloudflare DNS records');
        $results = $response->json('result');
        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, 'is_array'));
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

    /**
     * @param  array<string, mixed> $queryOrBody
     */
    private function request(string $method, string $path, array $queryOrBody = []): Response
    {
        $url = self::BASE.$path;

        // Two mutually exclusive schemes — sending a Global API Key as a Bearer
        // token authenticates as nobody and silently returns empty lists.
        $client = $this->authEmail !== ''
            ? Http::withHeaders([
                'X-Auth-Email' => $this->authEmail,
                'X-Auth-Key' => $this->bearerToken,
            ])->acceptJson()
            : Http::withToken($this->bearerToken)->acceptJson();

        return match (strtolower($method)) {
            'get' => $client->get($url, $queryOrBody),
            'post' => $client->asJson()->post($url, $queryOrBody),
            'put' => $client->asJson()->put($url, $queryOrBody),
            'delete' => $client->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }

    /**
     * Raw diagnostics straight from Cloudflare, for when the permissions LOOK
     * right but the zone list comes back empty.
     *
     * Returns the token's own identity (id + status) alongside the unfiltered
     * /zones body. The token id is the decisive field: the Cloudflare dashboard
     * shows it next to each token, so comparing it settles "is the token in
     * .env the same one I edited?" — a question a fingerprint cannot answer,
     * because you cannot hash the copy in the dashboard.
     *
     * Never throws: this runs when something is already wrong.
     *
     * @return array{token_id: ?string, token_status: ?string, verify_error: ?string, zones_status: ?int, zones_body: mixed}
     */
    public function rawDiagnostics(): array
    {
        $out = [
            'token_id' => null,
            'token_status' => null,
            'verify_error' => null,
            'zones_status' => null,
            'zones_body' => null,
        ];

        try {
            // Account-owned tokens (cfat_…) are rejected here even when valid,
            // so a failure is informative, not fatal.
            $verify = $this->request('get', '/user/tokens/verify');
            $out['token_id'] = is_string($verify->json('result.id')) ? $verify->json('result.id') : null;
            $out['token_status'] = is_string($verify->json('result.status')) ? $verify->json('result.status') : null;
            if ($out['token_id'] === null) {
                $out['verify_error'] = (string) ($verify->json('errors.0.message') ?? 'no token id returned');
            }
        } catch (\Throwable $e) {
            $out['verify_error'] = $e->getMessage();
        }

        try {
            $zones = $this->request('get', '/zones', ['per_page' => 50]);
            $out['zones_status'] = $zones->status();
            $out['zones_body'] = $zones->json();
        } catch (\Throwable $e) {
            $out['zones_body'] = ['exception' => $e->getMessage()];
        }

        return $out;
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

        // A token that can read zones but hits an auth/permission wall on records
        // is almost always missing Zone:DNS:Edit — say so rather than the bare
        // "Authentication error" Cloudflare returns.
        $code = (int) ($response->json('errors.0.code') ?? 0);
        if (in_array($code, [10000, 9109], true) || stripos((string) $message, 'authentication') !== false || $response->status() === 403) {
            $message .= ' — the API token needs the Zone:DNS:Edit permission for this zone.';
        }

        throw new \RuntimeException("Failed to {$action}: {$message}");
    }
}
