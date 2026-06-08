<?php

declare(strict_types=1);

namespace App\Services\Snapshots;

use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\Snapshot;
use App\Models\User;
use App\Notifications\SnapshotStatusNotification;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates database snapshot take + restore for the WordPress
 * Database sub-tab and the Laravel Migrations rollback safety net (PR 11).
 *
 * The runtime split — local disk vs S3 — happens in the destination
 * adapter ({@see SnapshotDestination}); this service just runs the
 * mysqldump/pg_dump on the server, captures the resulting file path
 * + size, and hands it to the destination to persist.
 *
 * Engine-specific dump invocations live here. We encapsulate the
 * "single-transaction" + "quick" footguns so callers don't need to
 * know about engine quirks (Q19 — mysqldump's MyISAM caveat,
 * pg_dump's --single-transaction story).
 */
class SnapshotService
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly SiteAuditWriter $audit,
    ) {}

    /**
     * Take a snapshot. The destination decides where it lands.
     */
    public function take(
        Site $site,
        SnapshotDestination $destination,
        string $reason,
        ?string $userId = null,
    ): Snapshot {
        $engine = $this->engineFor($site);
        $tmpPath = sprintf('/tmp/dply-snapshot-%s-%s.sql.gz', $site->slug, Str::random(8));
        $dbName = $this->databaseNameFor($site);

        $dumpCmd = $this->buildDumpCommand($engine, $dbName, $tmpPath);

        // Create the row up front in 'pending' so the Snapshots → Databases tab
        // shows the snapshot the instant it's queued and polls it to completion,
        // instead of the row only materializing once the dump lands. The
        // destination ({@see SnapshotDestination::persist()}) fills in the
        // location/size and flips it to completed.
        $snapshot = Snapshot::query()->create([
            'site_id' => $site->getKey(),
            'destination' => $destination->kind(),
            'engine' => $engine,
            'reason' => $reason,
            'taken_by_user_id' => $userId,
            'status' => Snapshot::STATUS_PENDING,
        ]);

        try {
            $out = $this->executor->runInlineBash(
                server: $site->server,
                name: 'snapshot:dump',
                inlineBash: $dumpCmd,
                timeoutSeconds: 1800,
            );
        } catch (Throwable $e) {
            Log::warning('SnapshotService dump failed', [
                'site_id' => $site->getKey(),
                'engine' => $engine,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($snapshot, $e->getMessage());
            $this->audit->record(
                site: $site,
                user: null,
                action: 'snapshot_failed',
                risk: RiskLevel::Destructive,
                transport: SiteAuditEvent::TRANSPORT_SYSTEM,
                summary: 'Snapshot dump failed',
                payload: ['reason' => $reason, 'error' => $e->getMessage()],
                resultStatus: SiteAuditEvent::RESULT_FAILURE,
            );

            // A failed/partial dump can still leave a file behind.
            $this->removeRemoteTmp($site, $tmpPath);
            $this->notifyOrgAdmins($site, Snapshot::STATUS_FAILED, $e->getMessage());

            throw $e;
        }

        if ($out->getExitCode() !== 0) {
            $this->removeRemoteTmp($site, $tmpPath);
            $message = "Dump command exited {$out->getExitCode()}: {$out->getBuffer()}";
            $this->markFailed($snapshot, $message);
            $this->notifyOrgAdmins($site, Snapshot::STATUS_FAILED, $message);

            throw new \RuntimeException($message);
        }

        // On success the destination consumes $tmpPath (local mv / S3
        // upload-then-rm); on any failure here the dump — a full copy of the
        // database — would linger in /tmp, so remove it before rethrowing.
        try {
            $bytes = $this->fileSizeOnServer($site, $tmpPath);
            $destination->persist($snapshot, $tmpPath, $bytes);
        } catch (Throwable $e) {
            $this->removeRemoteTmp($site, $tmpPath);
            $this->markFailed($snapshot, $e->getMessage());
            $this->notifyOrgAdmins($site, Snapshot::STATUS_FAILED, $e->getMessage());

            throw $e;
        }

        $this->audit->record(
            site: $site,
            user: $userId !== null ? User::query()->find($userId) : null,
            action: 'snapshot_taken',
            risk: RiskLevel::MutatingRecoverable,
            transport: $userId !== null ? SiteAuditEvent::TRANSPORT_WEB : SiteAuditEvent::TRANSPORT_SYSTEM,
            summary: sprintf('Snapshot taken (%s)', $reason),
            payload: ['snapshot_id' => $snapshot->id, 'destination' => $snapshot->destination, 'bytes' => $bytes],
        );

        // Only the explicit "Take snapshot" action emails a success note — the
        // automatic pre-destructive safety nets fire constantly and would spam.
        // Failures above always notify, regardless of reason.
        if ($reason === Snapshot::REASON_MANUAL) {
            $this->notifyOrgAdmins($site, Snapshot::STATUS_COMPLETED, '');
        }

        return $snapshot;
    }

    /** Flip a pending row to failed, recording the error. Never throws. */
    private function markFailed(Snapshot $snapshot, string $error): void
    {
        try {
            $snapshot->update([
                'status' => Snapshot::STATUS_FAILED,
                'error_message' => Str::limit($error, 2000),
            ]);
        } catch (Throwable) {
            // best-effort — must not mask the original failure
        }
    }

    /**
     * Email the org's owners/admins about a snapshot's completion or failure.
     * Best-effort: a notification problem must never break the snapshot flow.
     */
    private function notifyOrgAdmins(Site $site, string $status, string $errorMessage): void
    {
        try {
            $org = $site->server?->organization;
            if ($org === null) {
                return;
            }

            $admins = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new SnapshotStatusNotification(
                kind: 'database',
                status: $status,
                label: (string) ($site->name ?: $site->slug),
                serverName: (string) ($site->server?->name ?? ''),
                url: route('servers.snapshots', $site->server_id, absolute: true),
                errorMessage: $errorMessage,
            ));
        } catch (Throwable $e) {
            Log::warning('SnapshotService notify failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Restore. Always destructive — overwrites the live DB schema +
     * data with whatever the snapshot captures.
     */
    public function restore(Snapshot $snapshot, SnapshotDestination $destination, ?string $userId = null): void
    {
        try {
            $destination->restore($snapshot);
        } catch (Throwable $e) {
            $this->audit->record(
                site: $snapshot->site,
                user: $userId !== null ? User::query()->find($userId) : null,
                action: 'snapshot_restore_failed',
                risk: RiskLevel::Destructive,
                transport: $userId !== null ? SiteAuditEvent::TRANSPORT_WEB : SiteAuditEvent::TRANSPORT_SYSTEM,
                summary: 'Snapshot restore failed',
                payload: ['snapshot_id' => $snapshot->id, 'error' => $e->getMessage()],
                resultStatus: SiteAuditEvent::RESULT_FAILURE,
            );

            throw $e;
        }

        $this->audit->record(
            site: $snapshot->site,
            user: $userId !== null ? User::query()->find($userId) : null,
            action: 'snapshot_restored',
            risk: RiskLevel::Destructive,
            transport: $userId !== null ? SiteAuditEvent::TRANSPORT_WEB : SiteAuditEvent::TRANSPORT_SYSTEM,
            summary: sprintf('Snapshot %s restored', $snapshot->id),
            payload: ['snapshot_id' => $snapshot->id],
        );
    }

    /**
     * Engine-specific dump cmd. Wraps the right --single-transaction /
     * --quick / --routines flags so callers don't get bitten by MyISAM
     * locks (mysqldump) or transaction-boundary issues (pg_dump).
     */
    private function buildDumpCommand(string $engine, string $dbName, string $outputPath): string
    {
        $escapedDb = escapeshellarg($dbName);
        $escapedOut = escapeshellarg($outputPath);

        return match ($engine) {
            'postgres', 'postgres17', 'postgres18' => sprintf(
                'pg_dump --no-owner --no-privileges --quote-all-identifiers --format=plain %s | gzip > %s',
                $escapedDb,
                $escapedOut,
            ),
            default => sprintf(
                // --single-transaction: consistent on InnoDB; --quick: stream
                // huge tables instead of buffering; --routines + --triggers:
                // capture the schema people forget to back up.
                'mysqldump --single-transaction --quick --routines --triggers %s | gzip > %s',
                $escapedDb,
                $escapedOut,
            ),
        };
    }

    private function engineFor(Site $site): string
    {
        // Prefer the site's explicit override; fall back to the server's
        // declared engine; finally default to mysql84 (the safe assumption
        // for any historical site without an engine pin).
        $engine = (string) ($site->database_engine
            ?? $site->server->meta['database']
            ?? 'mysql84');

        return $engine;
    }

    private function databaseNameFor(Site $site): string
    {
        // Scaffolded sites record their DB name explicitly; everything
        // else uses the standard dply_<slug> convention.
        $recorded = $site->meta['scaffold']['database']['name'] ?? null;
        if (is_string($recorded) && $recorded !== '') {
            return $recorded;
        }

        return 'dply_'.Str::slug((string) $site->slug, '_');
    }

    private function fileSizeOnServer(Site $site, string $path): int
    {
        try {
            $out = $this->executor->runInlineBash(
                server: $site->server,
                name: 'snapshot:size',
                inlineBash: 'stat -c %s '.escapeshellarg($path).' 2>/dev/null || stat -f %z '.escapeshellarg($path),
                timeoutSeconds: 15,
            );
        } catch (Throwable) {
            return 0;
        }

        return (int) trim($out->getBuffer());
    }

    /**
     * Best-effort removal of a /tmp dump after a failed snapshot, so a full
     * copy of the database doesn't linger on the server. Never throws — it
     * must not mask the original failure it's cleaning up after.
     */
    private function removeRemoteTmp(Site $site, string $tmpPath): void
    {
        try {
            $this->executor->runInlineBash(
                server: $site->server,
                name: 'snapshot:cleanup-tmp',
                inlineBash: 'rm -f '.escapeshellarg($tmpPath),
                timeoutSeconds: 30,
            );
        } catch (Throwable) {
            // best-effort
        }
    }
}
