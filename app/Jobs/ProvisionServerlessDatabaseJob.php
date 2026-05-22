<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Deploy\ServerlessEnvironmentPreparer;
use App\Services\DigitalOceanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisions a DigitalOcean Managed Database cluster for a serverless
 * function and wires its connection into the function's managed environment.
 *
 * A cluster takes minutes to come online, so the job is its own poll loop:
 * it creates the cluster, then re-dispatches itself with a delay until the
 * cluster reports `online`. Once online it writes `DB_*` into the function's
 * `env_file_content` — picked up by the next deploy via the environment
 * preparer — and records the non-secret connection facts in site meta.
 */
class ProvisionServerlessDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /** Re-dispatch cap — ~13 min at 20s spacing, enough for a small cluster. */
    private const MAX_ATTEMPTS = 40;

    public function __construct(public string $siteId, public int $attempt = 1) {}

    public function handle(ServerlessEnvironmentPreparer $environment): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $site->loadMissing('server.providerCredential');
        $server = $site->server;

        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $database = is_array($serverless['database'] ?? null) ? $serverless['database'] : [];

        if ($database === [] || ($database['status'] ?? '') === 'online') {
            return;
        }

        $credential = $server?->providerCredential;
        if ($credential === null) {
            $this->fail($site, $database, 'The host has no DigitalOcean credential.');

            return;
        }

        $service = new DigitalOceanService($credential);

        try {
            if (empty($database['cluster_id'])) {
                $cluster = $service->createDatabaseCluster(
                    (string) ($database['engine'] ?? 'pg'),
                    $server->region !== '' ? (string) $server->region : 'nyc1',
                    (string) ($database['size'] ?? 'db-s-1vcpu-1gb'),
                    'dply-'.(Str::slug((string) $site->slug) ?: 'fn').'-'.Str::lower(Str::random(6)),
                );
                $database['cluster_id'] = $cluster['id'];
            } else {
                $cluster = $service->getDatabaseCluster((string) $database['cluster_id']);
            }
        } catch (Throwable $e) {
            Log::error('serverless.database.provision_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $this->fail($site, $database, $e->getMessage());

            return;
        }

        // Still spinning up — record progress and poll again shortly.
        if ($cluster['status'] !== 'online' || $cluster['connection']['host'] === '') {
            $database['status'] = 'provisioning';
            $this->persist($site, $database);

            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->fail($site, $database, 'The database cluster did not come online in time.');

                return;
            }

            self::dispatch($this->siteId, $this->attempt + 1)->delay(now()->addSeconds(20));

            return;
        }

        // Online — wire the connection into the function's managed environment.
        // For Postgres, route through a transaction-mode pool so bursty
        // cold-start connections do not exhaust the cluster's limit. Pool
        // failure is non-fatal: fall back to the direct connection.
        $connection = $cluster['connection'];
        $pooled = false;
        if ($cluster['engine'] === 'pg') {
            try {
                $pool = $service->createDatabaseConnectionPool(
                    (string) $database['cluster_id'],
                    'dply-pool',
                    $connection['database'],
                    $connection['user'],
                );
                if ($pool['connection']['host'] !== '') {
                    $connection = $pool['connection'];
                    $pooled = true;
                }
            } catch (Throwable $e) {
                Log::warning('serverless.database.pool_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        $environment->mergeKeys($site, [
            'DB_CONNECTION' => $cluster['engine'] === 'mysql' ? 'mysql' : 'pgsql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['user'],
            'DB_PASSWORD' => $connection['password'],
        ]);

        $database['status'] = 'online';
        $database['host'] = $connection['host'];
        $database['port'] = $connection['port'];
        $database['database'] = $connection['database'];
        $database['username'] = $connection['user'];
        $database['pooled'] = $pooled;
        unset($database['error']);
        $this->persist($site->fresh() ?? $site, $database);
    }

    /**
     * @param  array<string, mixed>  $database
     */
    private function persist(Site $site, array $database): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['database'] = $database;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $database
     */
    private function fail(Site $site, array $database, string $error): void
    {
        $database['status'] = 'error';
        $database['error'] = $error;
        $this->persist($site, $database);
    }
}
