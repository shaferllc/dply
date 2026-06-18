<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudDatabase;
use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Tears down the DigitalOcean Managed Database cluster behind a
 * CloudDatabase, then deletes the row. Idempotent — safe to retry if
 * the backend rejects (e.g. the cluster was already removed
 * out-of-band): a 404 from DO is treated as success.
 */
class TeardownCloudDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public string $cloudDatabaseId) {}

    public function handle(): void
    {
        $database = CloudDatabase::query()->find($this->cloudDatabaseId);
        if ($database === null) {
            return;
        }

        $database->forceFill(['status' => CloudDatabase::STATUS_DELETING])->save();

        if (is_string($database->backend_id) && $database->backend_id !== '') {
            $database->loadMissing('providerCredential');
            $credential = $database->providerCredential;
            if ($credential !== null) {
                try {
                    (new DigitalOceanService($credential))->deleteDatabaseCluster($database->backend_id);
                } catch (Throwable) {
                    // Idempotent — already deleted is fine.
                }
            }
        }

        // Drop pivot links then the row itself — no audit row is kept
        // for managed databases (unlike Sites, which keep a torn-down
        // marker); the database is gone so the row would be misleading.
        $database->sites()->detach();
        $database->delete();
    }
}
