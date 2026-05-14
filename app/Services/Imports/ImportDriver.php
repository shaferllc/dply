<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\ProviderCredential;

/**
 * Source-agnostic driver for reading a user's external hosting inventory (Ploi
 * today, Forge next) and orchestrating the side effects a migration needs on the
 * source side: pushing/revoking the ephemeral SSH key and putting the source
 * site into maintenance mode at cutover.
 *
 * Drivers are constructed with the ProviderCredential whose bearer token they
 * use. Returned arrays use the *source-shaped* keys (the integers the source
 * platform uses for IDs, the raw JSON it returns for detail snapshots) — the
 * sync layer normalises into dply-shaped rows. Keeping the source shape on the
 * driver boundary means a second driver (Forge) can return the same structural
 * keys without forcing a premature lowest-common-denominator schema.
 */
interface ImportDriver
{
    public function source(): string;

    /**
     * Verify the credential's bearer token authenticates and has the scopes
     * dply needs (server list + SSH-key management). Throws on failure.
     */
    public function validateConnection(): void;

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     ip_address: ?string,
     *     provider_label: ?string,
     *     server_type: ?string,
     *     php_versions: list<string>,
     *     status: ?string,
     *     raw: array<string, mixed>,
     * }>
     */
    public function listServers(): array;

    /**
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
    public function fetchServerDetail(int $sourceServerId): array;

    /**
     * @return list<array{
     *     id: int,
     *     domain: string,
     *     site_type: string,
     *     php_version: ?string,
     *     repository_url: ?string,
     *     repository_branch: ?string,
     *     web_directory: ?string,
     *     status: ?string,
     *     raw: array<string, mixed>,
     * }>
     */
    public function listSites(int $sourceServerId): array;

    /**
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
    public function fetchSiteDetail(int $sourceServerId, int $sourceSiteId): array;

    /**
     * Push a public key to the source server. Returns the source-side key id so
     * the revoke step can target it precisely.
     */
    public function pushSshKey(int $sourceServerId, string $label, string $publicKey): int;

    public function revokeSshKey(int $sourceServerId, int $sourceKeyId): void;

    /**
     * Raw .env content for the site (KEY=value lines).
     */
    public function fetchEnv(int $sourceServerId, int $sourceSiteId): string;

    /**
     * @return list<array{id: int, schedule: string, command: string, user: ?string, raw: array<string, mixed>}>
     */
    public function listSiteCrons(int $sourceServerId, int $sourceSiteId): array;

    /**
     * @return list<array{id: int, name: ?string, command: string, directory: ?string, user: ?string, processes: int, raw: array<string, mixed>}>
     */
    public function listDaemons(int $sourceServerId, int $sourceSiteId): array;

    /**
     * @return list<array{id: int, name: string, username: ?string, raw: array<string, mixed>}>
     */
    public function listSiteDatabases(int $sourceServerId, int $sourceSiteId): array;

    /**
     * @return ?array{id: int, issuer: ?string, domain: ?string, valid_until: ?string, status: ?string, raw: array<string, mixed>}
     */
    public function fetchSiteCertificate(int $sourceServerId, int $sourceSiteId): ?array;

    public function enableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void;

    public function disableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void;

    /**
     * @return list<array{id: int, url: string, raw: array<string, mixed>}>
     */
    public function listSiteWebhooks(int $sourceServerId, int $sourceSiteId): array;

    public function deleteSiteWebhook(int $sourceServerId, int $sourceSiteId, int $webhookId): void;
}

/**
 * Factory contract for driver resolution. Lets the sync/orchestration code ask
 * for a driver by source name without coupling to concrete classes.
 */
interface ImportDriverFactory
{
    public function for(ProviderCredential $credential): ImportDriver;
}
