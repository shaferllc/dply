<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudDatabase;
use App\Services\DigitalOceanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisions a DigitalOcean Managed Database cluster for a CloudDatabase
 * row and stores the connection block once the cluster is online.
 *
 * A cluster takes minutes to come online, so the job is its own poll
 * loop (mirrors ProvisionServerlessDatabaseJob): it creates the cluster,
 * stores the backend id, then re-dispatches itself with a delay until
 * the cluster reports `online`. Once online it stores the encrypted
 * connection block on the row and flips status to ACTIVE. On any
 * exception the row is marked FAILED with the error in `meta`.
 */
class ProvisionCloudDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Re-dispatch cap — ~13 min at 20s spacing, enough for a small cluster. */
    private const MAX_ATTEMPTS = 40;

    public function __construct(public string $cloudDatabaseId, public int $attempt = 1) {}

    public function handle(): void
    {
        $database = CloudDatabase::query()->find($this->cloudDatabaseId);
        if ($database === null) {
            return;
        }

        // Already settled — nothing to poll.
        if (in_array($database->status, [CloudDatabase::STATUS_ACTIVE, CloudDatabase::STATUS_DELETING], true)) {
            return;
        }

        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            $this->markFailed($database, 'The database has no DigitalOcean credential.');

            return;
        }

        $service = new DigitalOceanService($credential);

        try {
            if (! is_string($database->backend_id) || $database->backend_id === '') {
                $cluster = $service->createDatabaseCluster(
                    $database->backendEngineSlug(),
                    $database->region !== '' ? $database->region : 'nyc1',
                    $database->backendSizeSlug(),
                    $this->clusterName($database),
                );
                $database->forceFill(['backend_id' => $cluster['id']])->save();
            } else {
                $cluster = $service->getDatabaseCluster($database->backend_id);
            }
        } catch (Throwable $e) {
            Log::error('cloud.database.provision_failed', [
                'cloud_database_id' => $database->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($database, $e->getMessage());

            return;
        }

        // Still spinning up — re-poll shortly.
        if ($cluster['status'] !== 'online' || $cluster['connection']['host'] === '') {
            $database->forceFill(['status' => CloudDatabase::STATUS_PROVISIONING])->save();

            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->markFailed($database, 'The database cluster did not come online in time.');

                return;
            }

            self::dispatch($this->cloudDatabaseId, $this->attempt + 1)->delay(now()->addSeconds(20));

            return;
        }

        // Online — persist the connection block (encrypted) and the
        // non-secret provisioning facts.
        $connection = $cluster['connection'];
        $meta = $database->meta;
        unset($meta['error'], $meta['error_at']);
        $meta['provisioned_at'] = now()->toIso8601String();

        $database->forceFill([
            'status' => CloudDatabase::STATUS_ACTIVE,
            'connection' => [
                'host' => $connection['host'],
                'port' => $connection['port'],
                'username' => $connection['user'],
                'password' => $connection['password'],
                'database' => $connection['database'],
                'ssl' => $connection['ssl'],
            ],
            'meta' => $meta,
        ])->save();

        // The create-DB-alongside wizard path pivots a fresh Site to this
        // DB before its connection block exists. Now that the cluster is
        // online, fan out an attach per pivoted site so each one gets the
        // DB_* env vars merged + a redeploy queued.
        foreach ($database->sites()->pluck('sites.id') as $siteId) {
            AttachCloudDatabaseJob::dispatch((string) $database->id, (string) $siteId);
        }
    }

    private function clusterName(CloudDatabase $database): string
    {
        $slug = Str::slug($database->name) ?: 'db';

        return 'dply-'.$slug.'-'.Str::lower(Str::random(6));
    }

    private function markFailed(CloudDatabase $database, string $error): void
    {
        $meta = $database->meta;
        $meta['error'] = $error;
        $meta['error_at'] = now()->toIso8601String();

        $database->forceFill([
            'status' => CloudDatabase::STATUS_FAILED,
            'meta' => $meta,
        ])->save();
    }
}
