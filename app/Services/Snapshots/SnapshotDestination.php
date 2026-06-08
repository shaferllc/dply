<?php

declare(strict_types=1);

namespace App\Services\Snapshots;

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
     * The destination kind this adapter writes — one of {@see Snapshot::DESTINATION_*}.
     * {@see SnapshotService} stamps it on the pending row up front so the history
     * list shows "Disk" / "S3" before the dump has finished.
     */
    public function kind(): string;

    /**
     * Persist the dump bytes to wherever the destination lives, then fill in the
     * already-created (pending) Snapshot row with the resulting location + size and
     * flip it to completed. The row is pre-created by {@see SnapshotService::take()}
     * so it can surface as a "pending" entry the instant the snapshot is queued.
     *
     * @param  Snapshot  $snapshot  the pending row to update in place (already carries
     *                              site / reason / engine / taken_by_user_id).
     * @param  string  $dumpRemotePath  on-server path of the freshly-written gzipped
     *                                  dump file produced by SnapshotService — the
     *                                  destination is responsible for moving / streaming it out.
     */
    public function persist(Snapshot $snapshot, string $dumpRemotePath, int $bytes): Snapshot;

    /**
     * Stream the snapshot back into the live database. Idempotent in
     * the sense that the same Snapshot row can drive multiple restores
     * (the operator might roll back, then forward, then back again).
     */
    public function restore(Snapshot $snapshot): void;
}
