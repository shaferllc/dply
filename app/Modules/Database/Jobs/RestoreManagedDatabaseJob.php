<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Models\CloudDatabase;
use App\Modules\Database\Backends\DatabaseRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a new cluster from another cluster's backup.
 *
 * Restore is a create, not a mutation: the source is never touched, so a
 * restore taken against the wrong timestamp costs a cluster's hourly rate and
 * nothing else. That is the property worth preserving — it is also why the new
 * row is a full CloudDatabase the operator can attach, inspect and tear down
 * like any other, rather than a transient object.
 *
 * Same poll-loop shape as {@see ProvisionManagedDatabaseJob}: kick the create,
 * store the backend id, re-dispatch on a delay until the provider says online.
 * Restores seed from a snapshot and routinely take longer than a fresh create,
 * hence the larger attempt cap.
 */
class RestoreManagedDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** ~40 min at 30s spacing — a restore replays a snapshot, not a blank create. */
    private const MAX_ATTEMPTS = 80;

    public function __construct(
        public string $targetDatabaseId,
        public string $sourceDatabaseId,
        public string $backupCreatedAt,
        public int $attempt = 1,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(DatabaseRouter $router): void
    {
        $target = CloudDatabase::query()->find($this->targetDatabaseId);
        if ($target === null) {
            return;
        }

        // Torn down (or already settled) while the restore ran — stop polling.
        if (in_array($target->status, [CloudDatabase::STATUS_ACTIVE, CloudDatabase::STATUS_DELETING], true)) {
            return;
        }

        $source = CloudDatabase::query()->find($this->sourceDatabaseId);
        if ($source === null) {
            $this->markFailed($target, __('The source database no longer exists.'));

            return;
        }

        $backend = $router->backendFor($target);

        try {
            if (blank($target->backend_id)) {
                $backend->provisionFromBackup($target, $source, $this->backupCreatedAt);
                $target->refresh();
            }

            $result = $backend->poll($target);
        } catch (Throwable $e) {
            Log::error('database.managed.restore_failed', [
                'cloud_database_id' => $target->id,
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($target, $e->getMessage());

            return;
        }

        $connection = $result['connection'];
        $online = $result['status'] === 'online' && (string) ($connection['host'] ?? '') !== '';

        if (! $online) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->markFailed($target, __('The restored cluster did not come online in time.'));

                return;
            }

            self::dispatch(
                $this->targetDatabaseId,
                $this->sourceDatabaseId,
                $this->backupCreatedAt,
                $this->attempt + 1,
            )->delay(now()->addSeconds(30));

            return;
        }

        $meta = $target->meta;
        unset($meta['error'], $meta['error_at']);
        $meta['provisioned_at'] = now()->toIso8601String();

        $target->forceFill([
            'status' => CloudDatabase::STATUS_ACTIVE,
            'connection' => [
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (string) ($connection['port'] ?? ''),
                'username' => (string) ($connection['user'] ?? $connection['username'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) ($connection['database'] ?? ''),
                'ssl' => (bool) ($connection['ssl'] ?? true),
            ],
            'meta' => $meta,
        ])->save();

        // Deliberately not attached to anything. A restore exists to be
        // inspected before it replaces a live database; wiring it into the
        // source's sites automatically would be the one irreversible step in
        // an otherwise reversible operation.
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
