<?php

declare(strict_types=1);

namespace App\Services\Imports\Ploi;

use App\Models\ProviderCredential;
use App\Services\Imports\ImportDriver;
use RuntimeException;

/**
 * Reads inventory and manages ephemeral SSH keys on Ploi-managed servers.
 *
 * Ploi returns Laravel-shaped paginated responses ({data: [...], meta: {...}});
 * listServers/listSites paginate transparently to the caller. Field shapes are
 * normalised into the ImportDriver-typed arrays so the rest of dply doesn't
 * branch on `source = 'ploi' | 'forge'`.
 */
class PloiImportDriver implements ImportDriver
{
    public function __construct(protected PloiClient $client) {}

    public static function for(ProviderCredential $credential): self
    {
        if ($credential->provider !== 'ploi') {
            throw new \InvalidArgumentException(
                sprintf('Expected provider=ploi, got %s', $credential->provider)
            );
        }

        return new self(new PloiClient($credential));
    }

    public function source(): string
    {
        return 'ploi';
    }

    public function validateConnection(): void
    {
        // /user is the cheapest authenticated endpoint Ploi exposes.
        $response = $this->client->get('/user');
        $this->client->assertSuccess($response, 'validate connection');
    }

    public function listServers(): array
    {
        return array_map(
            fn (array $row): array => $this->normaliseServer($row),
            $this->paginated('/servers'),
        );
    }

    public function fetchServerDetail(int $sourceServerId): array
    {
        $response = $this->client->get("/servers/{$sourceServerId}");
        $this->client->assertSuccess($response, "fetch server {$sourceServerId}");
        $data = $this->extractObject($response->json(), 'data');

        return $this->normaliseServer($data);
    }

    public function listSites(int $sourceServerId): array
    {
        return array_map(
            fn (array $row): array => $this->normaliseSite($row),
            $this->paginated("/servers/{$sourceServerId}/sites"),
        );
    }

    public function fetchSiteDetail(int $sourceServerId, int $sourceSiteId): array
    {
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}");
        $this->client->assertSuccess($response, "fetch site {$sourceServerId}/{$sourceSiteId}");
        $data = $this->extractObject($response->json(), 'data');

        return $this->normaliseSite($data);
    }

    public function pushSshKey(int $sourceServerId, string $label, string $publicKey): int
    {
        $response = $this->client->post("/servers/{$sourceServerId}/keys", [
            'name' => $label,
            'key' => $publicKey,
        ]);
        $this->client->assertSuccess($response, "push ssh key to server {$sourceServerId}");
        $payload = $response->json();
        $row = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : (is_array($payload) ? $payload : []);
        $id = $row['id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new RuntimeException('Ploi did not return SSH key id after push.');
        }

        return (int) $id;
    }

    public function revokeSshKey(int $sourceServerId, int $sourceKeyId): void
    {
        $response = $this->client->delete("/servers/{$sourceServerId}/keys/{$sourceKeyId}");
        $this->client->assertSuccess($response, "revoke ssh key {$sourceKeyId} on server {$sourceServerId}");
    }

    public function fetchEnv(int $sourceServerId, int $sourceSiteId): string
    {
        // Ploi exposes the raw .env content at /servers/{server}/sites/{site}/env
        // returning {data: {content: "KEY=value\n..."}}
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}/env");
        $this->client->assertSuccess($response, "fetch env for site {$sourceServerId}/{$sourceSiteId}");
        $payload = $response->json();
        if (! is_array($payload)) {
            return '';
        }
        $envelope = $payload['data'] ?? $payload;
        if (is_array($envelope) && isset($envelope['content']) && is_string($envelope['content'])) {
            return $envelope['content'];
        }

        return '';
    }

    public function listSiteCrons(int $sourceServerId, int $sourceSiteId): array
    {
        $rows = $this->paginated("/servers/{$sourceServerId}/sites/{$sourceSiteId}/crons");

        return array_values(array_map(
            fn (array $r): array => [
                'id' => (int) ($r['id'] ?? 0),
                'schedule' => (string) ($r['frequency'] ?? $r['schedule'] ?? ''),
                'command' => (string) ($r['command'] ?? ''),
                'user' => $this->nullableString($r['user'] ?? null),
                'raw' => $r,
            ],
            $rows,
        ));
    }

    public function listDaemons(int $sourceServerId, int $sourceSiteId): array
    {
        // Ploi exposes daemons at server level with a site filter, or at site level
        // depending on API version. Try site-scoped first; the index normalises to a list.
        $rows = $this->paginated("/servers/{$sourceServerId}/sites/{$sourceSiteId}/daemons");

        return array_values(array_map(
            fn (array $r): array => [
                'id' => (int) ($r['id'] ?? 0),
                'name' => $this->nullableString($r['name'] ?? null),
                'command' => (string) ($r['command'] ?? ''),
                'directory' => $this->nullableString($r['directory'] ?? null),
                'user' => $this->nullableString($r['user'] ?? null),
                'processes' => (int) ($r['processes'] ?? 1),
                'raw' => $r,
            ],
            $rows,
        ));
    }

    public function listSiteDatabases(int $sourceServerId, int $sourceSiteId): array
    {
        $rows = $this->paginated("/servers/{$sourceServerId}/sites/{$sourceSiteId}/databases");

        return array_values(array_map(
            fn (array $r): array => [
                'id' => (int) ($r['id'] ?? 0),
                'name' => (string) ($r['name'] ?? ''),
                'username' => $this->nullableString($r['user'] ?? $r['username'] ?? null),
                'raw' => $r,
            ],
            $rows,
        ));
    }

    public function fetchSiteCertificate(int $sourceServerId, int $sourceSiteId): ?array
    {
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}/certificates");
        // Some Ploi accounts may not have certificates; treat 404 as null rather than error.
        if ($response->status() === 404) {
            return null;
        }
        $this->client->assertSuccess($response, "fetch certificates for site {$sourceServerId}/{$sourceSiteId}");
        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }
        $data = $payload['data'] ?? [];
        if (! is_array($data) || $data === []) {
            return null;
        }
        // Prefer the active LE-issued certificate; fall back to whatever is most recent.
        $primary = null;
        foreach ($data as $cert) {
            if (! is_array($cert)) {
                continue;
            }
            $status = $cert['status'] ?? null;
            if ($status === 'active') {
                $primary = $cert;
                break;
            }
        }
        $cert = $primary ?? $data[0];
        if (! is_array($cert)) {
            return null;
        }

        return [
            'id' => (int) ($cert['id'] ?? 0),
            'issuer' => $this->nullableString($cert['type'] ?? null),
            'domain' => $this->nullableString($cert['domain'] ?? null),
            'valid_until' => $this->nullableString($cert['expires_at'] ?? null),
            'status' => $this->nullableString($cert['status'] ?? null),
            'raw' => $cert,
        ];
    }

    public function enableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void
    {
        $response = $this->client->post("/servers/{$sourceServerId}/sites/{$sourceSiteId}/maintenance");
        $this->client->assertSuccess($response, "enable maintenance for site {$sourceServerId}/{$sourceSiteId}");
    }

    public function disableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void
    {
        $response = $this->client->delete("/servers/{$sourceServerId}/sites/{$sourceSiteId}/maintenance");
        $this->client->assertSuccess($response, "disable maintenance for site {$sourceServerId}/{$sourceSiteId}");
    }

    public function listSiteWebhooks(int $sourceServerId, int $sourceSiteId): array
    {
        $rows = $this->paginated("/servers/{$sourceServerId}/sites/{$sourceSiteId}/deploy-keys");
        // Ploi's deploy-keys endpoint also reports webhooks; some accounts use a separate
        // /webhooks endpoint. Use the deploy-keys API as the primary source — it includes
        // the webhook URLs used by repository auto-deploy.
        return array_values(array_map(
            fn (array $r): array => [
                'id' => (int) ($r['id'] ?? 0),
                'url' => (string) ($r['url'] ?? ''),
                'raw' => $r,
            ],
            $rows,
        ));
    }

    public function deleteSiteWebhook(int $sourceServerId, int $sourceSiteId, int $webhookId): void
    {
        $response = $this->client->delete("/servers/{$sourceServerId}/sites/{$sourceSiteId}/deploy-keys/{$webhookId}");
        $this->client->assertSuccess($response, "delete webhook {$webhookId} on site {$sourceServerId}/{$sourceSiteId}");
    }

    /**
     * Walk Ploi's paginated index endpoints and return the concatenated `data` rows.
     * Defensive against either Laravel-shape (`meta.last_page`) or no-pagination shape.
     *
     * @return list<array<string, mixed>>
     */
    protected function paginated(string $path): array
    {
        $rows = [];
        $page = 1;
        $hardLimit = 200; // safety net against runaway pagination

        while ($page <= $hardLimit) {
            $response = $this->client->get($path, ['page' => $page]);
            $this->client->assertSuccess($response, "GET {$path} page {$page}");
            $payload = $response->json();
            if (! is_array($payload)) {
                break;
            }
            $data = $payload['data'] ?? [];
            if (! is_array($data) || $data === []) {
                break;
            }
            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $meta = $payload['meta'] ?? null;
            $lastPage = is_array($meta) && isset($meta['last_page']) ? (int) $meta['last_page'] : $page;
            if ($page >= $lastPage) {
                break;
            }
            $page++;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     id: int,
     *     name: string,
     *     ip_address: ?string,
     *     provider_label: ?string,
     *     server_type: ?string,
     *     php_versions: list<string>,
     *     status: ?string,
     *     raw: array<string, mixed>,
     * }
     */
    protected function normaliseServer(array $row): array
    {
        $phpVersions = [];
        $singleVersion = $row['php_version'] ?? null;
        if (is_string($singleVersion) && $singleVersion !== '') {
            $phpVersions[] = $singleVersion;
        }
        $multiVersions = $row['php_versions'] ?? null;
        if (is_array($multiVersions)) {
            foreach ($multiVersions as $v) {
                if (is_string($v) && $v !== '' && ! in_array($v, $phpVersions, true)) {
                    $phpVersions[] = $v;
                }
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'ip_address' => $this->nullableString($row['ip_address'] ?? null),
            'provider_label' => $this->nullableString($row['type'] ?? null),
            'server_type' => $this->nullableString($row['server_type'] ?? $row['size'] ?? null),
            'php_versions' => $phpVersions,
            'status' => $this->nullableString($row['status'] ?? null),
            'raw' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     id: int,
     *     domain: string,
     *     site_type: string,
     *     php_version: ?string,
     *     repository_url: ?string,
     *     repository_branch: ?string,
     *     web_directory: ?string,
     *     status: ?string,
     *     raw: array<string, mixed>,
     * }
     */
    protected function normaliseSite(array $row): array
    {
        $repoProvider = $this->nullableString($row['repository_provider'] ?? null);
        $repoName = $this->nullableString($row['repository'] ?? null);
        $repoUrl = $this->nullableString($row['repository_url'] ?? null);
        if ($repoUrl === null && $repoName !== null && $repoProvider !== null) {
            $repoUrl = match ($repoProvider) {
                'github' => "git@github.com:{$repoName}.git",
                'gitlab' => "git@gitlab.com:{$repoName}.git",
                'bitbucket' => "git@bitbucket.org:{$repoName}.git",
                default => $repoName,
            };
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'domain' => (string) ($row['domain'] ?? ''),
            'site_type' => (string) ($row['kind'] ?? $row['type'] ?? 'custom'),
            'php_version' => $this->nullableString($row['php_version'] ?? null),
            'repository_url' => $repoUrl,
            'repository_branch' => $this->nullableString($row['branch'] ?? null),
            'web_directory' => $this->nullableString($row['web_directory'] ?? null),
            'status' => $this->nullableString($row['status'] ?? null),
            'raw' => $row,
        ];
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>
     */
    protected function extractObject($payload, string $envelopeKey): array
    {
        if (! is_array($payload)) {
            throw new RuntimeException('Ploi response was not a JSON object.');
        }
        $inner = $payload[$envelopeKey] ?? $payload;
        if (! is_array($inner)) {
            throw new RuntimeException("Ploi response missing '{$envelopeKey}' object.");
        }

        return $inner;
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
