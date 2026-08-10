<?php

declare(strict_types=1);

namespace App\Modules\Queue\Actions;

use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Facades\Log;

/**
 * Tear a namespace down: revoke its credentials, drop its jobs, delete the row.
 *
 * Order matters. Credentials are revoked *first* so that nothing can push into
 * a namespace being deleted — otherwise a client mid-flight could enqueue a job
 * between the purge and the delete, and that job would outlive the namespace
 * with nothing left able to reach it.
 *
 * Usage rollups are deliberately left alone. They are org-scoped and reference
 * no namespace, because the month a namespace was billed for outlives the
 * namespace itself.
 */
final class DeleteQueueNamespace
{
    public function __construct(
        private readonly QueueStore $store,
        private readonly RevokeQueueCredential $revoke,
    ) {}

    /**
     * @return array{jobs: int, failed: int, credentials: int}
     */
    public function handle(QueueNamespace $namespace): array
    {
        $credentials = $namespace->credentials()->get();

        foreach ($credentials as $credential) {
            /** @var QueueCredential $credential */
            $this->revoke->handle($credential);
        }

        // Belt and braces alongside the revocations above: a cached credential
        // tuple carries the epoch it was minted under, so this invalidates any
        // that a node resolved microseconds before the revoke landed.
        $namespace->bumpCredentialEpoch();

        $purged = $this->store->purge($namespace);

        Log::info('queue.namespace_deleted', [
            'namespace_id' => $namespace->id,
            'organization_id' => $namespace->organization_id,
            'jobs' => $purged['jobs'],
            'failed' => $purged['failed'],
        ]);

        $namespace->credentials()->delete();
        $namespace->delete();

        return [
            'jobs' => $purged['jobs'],
            'failed' => $purged['failed'],
            'credentials' => $credentials->count(),
        ];
    }
}
