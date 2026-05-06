<?php

declare(strict_types=1);

namespace App\Services\Snapshots;

use App\Models\Site;
use App\Models\Snapshot;

/**
 * Pluggable destination for a database snapshot — local disk for the
 * transient safety net (Q19), or BYO S3-compatible bucket for the
 * archive product (DO Spaces / B2 / R2 / S3).
 *
 * Each implementation owns its own "where does this end up + how do
 * we name it + how do we restore from it" logic. {@see SnapshotService}
 * picks the destination per-call.
 */
interface SnapshotDestination
{
    /**
     * Persist the dump bytes to wherever the destination lives, then
     * write a Snapshot row recording the location + size + reason.
     *
     * @param  string  $reason  one of {@see Snapshot::REASON_*}
     * @param  string  $dumpRemotePath  on-server path of the freshly-written
     *                                  gzipped dump file produced by SnapshotService — the destination
     *                                  is responsible for moving / streaming it out.
     */
    public function persist(Site $site, string $reason, string $dumpRemotePath, int $bytes, string $engine, ?string $userId): Snapshot;

    /**
     * Stream the snapshot back into the live database. Idempotent in
     * the sense that the same Snapshot row can drive multiple restores
     * (the operator might roll back, then forward, then back again).
     */
    public function restore(Snapshot $snapshot): void;
}
