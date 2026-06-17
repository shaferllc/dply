<?php

declare(strict_types=1);

namespace App\Services\Imports\Forge;

use App\Models\ProviderCredential;
use App\Services\Imports\ImportDriver;
use RuntimeException;

/**
 * Forge import driver. Forge's API differs from Ploi's in a few ways the
 * driver smooths over for the rest of the system:
 *
 *   - Response envelopes are plural-keyed ({servers: [...]} / {sites: [...]}
 *     / {jobs: [...]}) instead of Ploi's flat {data: [...]}.
 *   - Crons (Forge: "scheduled jobs"), daemons, and databases live at the
 *     server level on Forge; the per-site listing endpoints we expose
 *     filter the server-level list by best-effort matching (site directory
 *     for daemons; user for databases) to fit the ImportDriver contract.
 *   - Maintenance mode for Laravel sites is integrations/laravel-maintenance
 *     (POST = enable, DELETE = disable).
 *   - Forge sites use `name` for the domain and `project_type` instead of Ploi's
 *     `domain`/`kind`. Both are normalised here.
 *
 * Per-site cron/daemon scoping is "best-effort" because Forge's data model
 * doesn't enforce a hard binding to a site — these reflect the user's
 * scheduling intent at the server level. Items returned could over- or
 * under-include for a given site; the orchestrator's recreate-* handlers
 * already use replace-by-source-id so re-runs converge on the latest pull.
 */
class ForgeImportDriver implements ImportDriver
{
    public function __construct(protected ForgeClient $client) {}

    public static function for(ProviderCredential $credential): self
    {
        if ($credential->provider !== 'forge') {
            throw new \InvalidArgumentException(
                sprintf('Expected provider=forge, got %s', $credential->provider)
            );
        }

        return new self(new ForgeClient($credential));
    }

    public function source(): string
    {
        return 'forge';
    }

    public function validateConnection(): void
    {
        // Forge's analogue to /user is /user; both expose a small object on
        // a valid token. We hit /servers because Forge's /user endpoint is
        // less consistent across plans — list-servers is the canonical
        // "is my token alive" probe.
        $response = $this->client->get('/servers');
        $this->client->assertSuccess($response, 'validate connection');
    }

    /**
     * @return list<array<string, array<int<0, max>|string, mixed>|int|string|null>>
     */
    public function listServers(): array
    {
        $response = $this->client->get('/servers');
        $this->client->assertSuccess($response, 'list servers');

        $rows = $this->collectionFrom($response->json(), 'servers');

        return array_values(array_map(
            fn (array $row): array => $this->normaliseServer($row),
            $rows,
        ));
    }

    /**
     * @return list<array<string, array<int<0, max>|string, mixed>|int|string|null>>
     */
    public function fetchServerDetail(int $sourceServerId): array
    {
        $response = $this->client->get("/servers/{$sourceServerId}");
        $this->client->assertSuccess($response, "fetch server {$sourceServerId}");
        $row = $this->singleFrom($response->json(), 'server');

        return $this->normaliseServer($row);
    }

    /**
     * @return list<array<string, array<string, mixed>|int|string|null>>
     */
    public function listSites(int $sourceServerId): array
    {
        $response = $this->client->get("/servers/{$sourceServerId}/sites");
        $this->client->assertSuccess($response, "list sites for server {$sourceServerId}");
        $rows = $this->collectionFrom($response->json(), 'sites');

        return array_values(array_map(
            fn (array $row): array => $this->normaliseSite($row),
            $rows,
        ));
    }

    /**
     * @return list<array<string, array<string, mixed>|int|string|null>>
     */
    public function fetchSiteDetail(int $sourceServerId, int $sourceSiteId): array
    {
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}");
        $this->client->assertSuccess($response, "fetch site {$sourceServerId}/{$sourceSiteId}");
        $row = $this->singleFrom($response->json(), 'site');

        return $this->normaliseSite($row);
    }

    public function pushSshKey(int $sourceServerId, string $label, string $publicKey): int
    {
        $response = $this->client->post("/servers/{$sourceServerId}/keys", [
            'name' => $label,
            'key' => $publicKey,
        ]);
        $this->client->assertSuccess($response, "push ssh key to server {$sourceServerId}");
        $payload = $response->json();
        $row = is_array($payload) ? ($payload['key'] ?? $payload) : [];
        $id = $row['id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new RuntimeException('Forge did not return SSH key id after push.');
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
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}/env");
        $this->client->assertSuccess($response, "fetch env for site {$sourceServerId}/{$sourceSiteId}");

        // Forge returns the raw .env file content as the response body, not
        // a JSON envelope. The Http facade exposes ->body() for that.
        return (string) $response->body();
    }

    /**
     * @return list<array<string, array<string, mixed>|int|string|null>>
     */
    public function listSiteCrons(int $sourceServerId, int $sourceSiteId): array
    {
        // Forge: crons are server-level "scheduled jobs". For per-site
        // recreation we walk the server-level list and best-effort filter
        // for commands referencing the site's directory.
        $site = $this->fetchSiteDetail($sourceServerId, $sourceSiteId);
        $siteName = $site['domain'];

        $response = $this->client->get("/servers/{$sourceServerId}/jobs");
        $this->client->assertSuccess($response, "list jobs for server {$sourceServerId}");
        $rows = $this->collectionFrom($response->json(), 'jobs');

        $hits = [];
        foreach ($rows as $row) {
            $command = (string) ($row['command'] ?? '');
            if ($command === '') {
                continue;
            }
            // Forge's site dir convention is /home/forge/{name}; cron commands typically
            // reference that. The match is "best effort"; sync handlers do replace-by-id
            // so over-inclusion is corrected on re-runs.
            if (str_contains($command, '/home/forge/'.$siteName) || str_contains($command, $siteName)) {
                $hits[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'schedule' => (string) ($row['frequency']['cron'] ?? $row['cron'] ?? $row['frequency'] ?? ''),
                    'command' => $command,
                    'user' => $this->nullableString($row['user'] ?? null),
                    'raw' => $row,
                ];
            }
        }

        return $hits;
    }

    /**
     * @return list<array<string, array<string, mixed>|int|string|null>>
     */
    public function listDaemons(int $sourceServerId, int $sourceSiteId): array
    {
        $site = $this->fetchSiteDetail($sourceServerId, $sourceSiteId);
        $siteName = $site['domain'];

        $response = $this->client->get("/servers/{$sourceServerId}/daemons");
        $this->client->assertSuccess($response, "list daemons for server {$sourceServerId}");
        $rows = $this->collectionFrom($response->json(), 'daemons');

        $hits = [];
        foreach ($rows as $row) {
            $directory = (string) ($row['directory'] ?? '');
            $command = (string) ($row['command'] ?? '');
            if ($command === '') {
                continue;
            }
            $matchesSiteDir = $directory !== '' && str_contains($directory, $siteName);
            $matchesCommand = str_contains($command, '/home/forge/'.$siteName) || str_contains($command, $siteName);
            if (! $matchesSiteDir && ! $matchesCommand) {
                continue;
            }
            $hits[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $this->nullableString($row['command'] ?? null) === null
                    ? null
                    : ('forge-'.$row['id']),
                'command' => $command,
                'directory' => $this->nullableString($directory),
                'user' => $this->nullableString($row['user'] ?? null),
                'processes' => (int) ($row['processes'] ?? 1),
                'raw' => $row,
            ];
        }

        return $hits;
    }

    /**
     * @return list<array<string, array<string, mixed>|int|string|null>>
     */
    public function listSiteDatabases(int $sourceServerId, int $sourceSiteId): array
    {
        // Forge databases are server-level. For per-site listing we match by user
        // — convention is the site's user owns its database.
        $site = $this->fetchSiteDetail($sourceServerId, $sourceSiteId);
        $expectedUser = $this->nullableString($site['raw']['user'] ?? null);

        $response = $this->client->get("/servers/{$sourceServerId}/databases");
        $this->client->assertSuccess($response, "list databases for server {$sourceServerId}");
        $rows = $this->collectionFrom($response->json(), 'databases');

        $hits = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            // Forge databases nest users under ['users' => [{id, name}, ...]]; check if
            // any owner matches the site's expected user.
            $users = is_array($row['users'] ?? null) ? $row['users'] : [];
            $matches = false;
            if ($expectedUser !== null) {
                foreach ($users as $u) {
                    if (is_array($u) && ($u['name'] ?? null) === $expectedUser) {
                        $matches = true;
                        break;
                    }
                }
            }
            // Fall back to: if no expected user, include all; if expected but no users at all, include
            // (defensive, Forge sometimes omits the relation).
            if ($expectedUser === null || $matches || $users === []) {
                $hits[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => $name,
                    'username' => $expectedUser,
                    'raw' => $row,
                ];
            }
        }

        return $hits;
    }

    public function fetchSiteCertificate(int $sourceServerId, int $sourceSiteId): ?array
    {
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}/certificates");
        if ($response->status() === 404) {
            return null;
        }
        $this->client->assertSuccess($response, "fetch certificates for site {$sourceServerId}/{$sourceSiteId}");
        $rows = $this->collectionFrom($response->json(), 'certificates');
        if ($rows === []) {
            return null;
        }
        // Prefer an active LE cert; fall back to the first.
        $primary = null;
        foreach ($rows as $cert) {
            if (! is_array($cert)) {
                continue;
            }
            if (($cert['active'] ?? false) === true) {
                $primary = $cert;
                break;
            }
        }
        $cert = $primary ?? $rows[0];

        return [
            'id' => (int) ($cert['id'] ?? 0),
            'issuer' => $this->nullableString($cert['type'] ?? 'letsencrypt'),
            'domain' => $this->nullableString($cert['domain'] ?? null),
            'valid_until' => $this->nullableString($cert['expires_at'] ?? null),
            'status' => ($cert['active'] ?? false) === true ? 'active' : 'inactive',
            'raw' => $cert,
        ];
    }

    public function enableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void
    {
        $response = $this->client->post("/servers/{$sourceServerId}/sites/{$sourceSiteId}/integrations/laravel-maintenance");
        $this->client->assertSuccess($response, "enable maintenance for site {$sourceServerId}/{$sourceSiteId}");
    }

    public function disableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void
    {
        $response = $this->client->delete("/servers/{$sourceServerId}/sites/{$sourceSiteId}/integrations/laravel-maintenance");
        $this->client->assertSuccess($response, "disable maintenance for site {$sourceServerId}/{$sourceSiteId}");
    }

    /**
     * @return list<array<string, array<string, mixed>|int|string>>
     */
    public function listSiteWebhooks(int $sourceServerId, int $sourceSiteId): array
    {
        $response = $this->client->get("/servers/{$sourceServerId}/sites/{$sourceSiteId}/git/webhooks");
        if ($response->status() === 404) {
            return [];
        }
        $this->client->assertSuccess($response, "list webhooks for site {$sourceServerId}/{$sourceSiteId}");
        $rows = $this->collectionFrom($response->json(), 'webhooks');

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
        $response = $this->client->delete("/servers/{$sourceServerId}/sites/{$sourceSiteId}/git/webhooks/{$webhookId}");
        $this->client->assertSuccess($response, "delete webhook {$webhookId} on site {$sourceServerId}/{$sourceSiteId}");
    }

    /**
     * @param  array<string, mixed> $row
     * @return list<array<string, array<string, mixed>|int|string>>
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
        // Forge uses string keys like "php82" for current version.
        $singleVersion = $row['php_version'] ?? null;
        if (is_string($singleVersion) && $singleVersion !== '') {
            $phpVersions[] = $this->humanisePhpVersion($singleVersion);
        }
        // Forge also exposes installed alternates under 'php_versions' on /servers/{id} detail.
        $multi = $row['php_versions'] ?? null;
        if (is_array($multi)) {
            foreach ($multi as $v) {
                $version = is_array($v) ? ($v['version'] ?? null) : $v;
                if (is_string($version) && $version !== '') {
                    $h = $this->humanisePhpVersion($version);
                    if (! in_array($h, $phpVersions, true)) {
                        $phpVersions[] = $h;
                    }
                }
            }
        }

        $status = null;
        if (isset($row['is_ready'])) {
            $status = $row['is_ready'] === true ? 'active' : 'provisioning';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'ip_address' => $this->nullableString($row['ip_address'] ?? null),
            'provider_label' => $this->nullableString($row['provider'] ?? null),
            'server_type' => $this->nullableString($row['size'] ?? null),
            'php_versions' => $phpVersions,
            'status' => $status,
            'raw' => $row,
        ];
    }

    /**
     * @param  array<string, mixed> $row
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
        $repo = $this->nullableString($row['repository'] ?? null);
        $repoUrl = null;
        if ($repo !== null && $repoProvider !== null) {
            $repoUrl = match ($repoProvider) {
                'github' => "git@github.com:{$repo}.git",
                'gitlab' => "git@gitlab.com:{$repo}.git",
                'bitbucket' => "git@bitbucket.org:{$repo}.git",
                default => $repo,
            };
        } elseif ($repo !== null) {
            $repoUrl = $repo;
        }

        // Forge site type → dply site_type (laravel/php/static; Forge also has 'html').
        $forgeType = (string) ($row['project_type'] ?? 'php');
        $siteType = match ($forgeType) {
            'php', 'laravel' => $forgeType,
            'html', 'static' => 'static',
            default => $forgeType,
        };

        return [
            'id' => (int) ($row['id'] ?? 0),
            'domain' => (string) ($row['name'] ?? ''),
            'site_type' => $siteType,
            'php_version' => $this->humanisePhpVersion($row['php_version'] ?? null),
            'repository_url' => $repoUrl,
            'repository_branch' => $this->nullableString($row['repository_branch'] ?? null),
            'web_directory' => $this->nullableString($row['directory'] ?? null) ?? '/public',
            'status' => $this->nullableString($row['status'] ?? null),
            'raw' => $row,
        ];
    }

    /**
     * Forge encodes versions as "php82" / "php83"; normalise to "8.2" / "8.3"
     * @param  array<string, mixed> $row
     * to match Ploi's shape.
     */
    protected function humanisePhpVersion(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^php(\d)(\d+)$/', $value, $m) === 1) {
            return $m[1].'.'.$m[2];
        }

        return $value;
    }

    /**
     * @param  mixed  $payload
     * @return list<array<string, mixed>>
     */
    protected function collectionFrom($payload, string $envelopeKey): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $list = $payload[$envelopeKey] ?? $payload;
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>
     */
    protected function singleFrom($payload, string $envelopeKey): array
    {
        if (! is_array($payload)) {
            throw new RuntimeException('Forge response was not a JSON object.');
        }
        $inner = $payload[$envelopeKey] ?? $payload;
        if (! is_array($inner)) {
            throw new RuntimeException("Forge response missing '{$envelopeKey}' object.");
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
