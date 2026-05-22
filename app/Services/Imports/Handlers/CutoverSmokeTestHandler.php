<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Jobs\IssueSiteSslJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\StepHandler;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cutover final step: hit the migrated site via its production domain to
 * verify DNS has propagated to dply AND the new server is responding. The
 * dply nginx vhost is set up to emit `X-Dply-Migration: cutover-verify`
 * for any host header it owns; we look for that header (vs. fall-back
 * landing pages or Ploi serving the request).
 *
 * Polls for up to 5 minutes (60 * 5s) so the user doesn't need to baby-sit
 * the page through TTL propagation. On success, marks the child site
 * migration completed and stamps cutover_completed_at.
 */
class CutoverSmokeTestHandler implements StepHandler
{
    public const POLL_ATTEMPTS = 60;

    public const POLL_INTERVAL_SECONDS = 5;

    public function __construct(protected ?NotificationPublisher $publisher = null) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('cutover_smoke_test requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('cutover_smoke_test requires a target_site_id.');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        $target = $migration?->target_server_id ? Server::find($migration->target_server_id) : null;
        if ($target === null) {
            throw new RuntimeException('Target dply server missing.');
        }

        $domain = $child->domain;
        $sawHeader = false;
        $lastStatus = null;
        $attempts = 0;
        $headerName = 'X-Dply-Migration';

        for ($i = 0; $i < static::POLL_ATTEMPTS; $i++) {
            $attempts++;
            try {
                // Probe via the public hostname first; the propagation check requires
                // public DNS resolution AND new-server response. Use the system resolver
                // (cached) — if cutover_dns_swap actually swapped via API, propagation
                // is fast; otherwise this polls until manual DNS update propagates.
                $response = Http::timeout(8)
                    ->withoutVerifying() // bridged certs may not yet pass strict verify
                    ->withHeaders(['User-Agent' => 'dply-migration-smoke/1.0'])
                    ->get('https://'.$domain.'/');
                $lastStatus = $response->status();
                $headerValue = $response->header($headerName);
                if ($headerValue !== null && $headerValue !== '') {
                    $sawHeader = true;
                    break;
                }
                if ($lastStatus >= 200 && $lastStatus < 500) {
                    // Site responded but without our marker — likely Ploi is still serving.
                    // Keep polling for DNS to swing over.
                }
            } catch (\Throwable) {
                // Network errors during propagation are expected; keep polling.
            }

            if ($i < static::POLL_ATTEMPTS - 1) {
                sleep(static::POLL_INTERVAL_SECONDS);
            }
        }

        if (! $sawHeader) {
            throw new RuntimeException(sprintf(
                'Smoke test failed after %d attempts (last status: %s). Either DNS has not swung to dply yet, or the new server is not serving the expected header. Retry after waiting for propagation.',
                $attempts,
                $lastStatus ?? 'no response'
            ));
        }

        $child->status = ImportSiteMigration::STATUS_COMPLETED;
        $child->cutover_completed_at = Carbon::now();
        $child->save();

        // Gap-strategy sites have ssl_status=NONE entering cutover; once DNS now
        // points at dply, HTTP-01 issuance can succeed. Dispatch the job.
        if ($child->ssl_strategy === ImportSiteMigration::SSL_GAP) {
            IssueSiteSslJob::dispatch($site->id);
        }

        $step->result_data = [
            'attempts' => $attempts,
            'last_status' => $lastStatus,
            'verified_header' => $headerName,
            'ssl_issuance_queued' => $child->ssl_strategy === ImportSiteMigration::SSL_GAP,
        ];
        $step->save();

        $this->publishCutoverComplete($migration, $child);
        $this->writeCutoverCompletedAudit($migration, $child);
        $this->maybeMarkMigrationComplete($migration);
    }

    /**
     * Audit-trail entry. No user context (the smoke-test handler runs from
     * the queue) so the user_id is the migration's originator if available.
     */
    protected function writeCutoverCompletedAudit(ImportServerMigration $migration, ImportSiteMigration $child): void
    {
        try {
            if ($migration->organization) {
                audit_log(
                    $migration->organization,
                    User::find($migration->user_id),
                    'import.migration.cutover_completed',
                    $migration,
                    null,
                    [
                        'site_migration_id' => $child->id,
                        'domain' => $child->domain,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('failed to write cutover-completed audit log', [
                'migration_id' => $migration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Surface "cutover complete: {domain}" in the inbox + email queue. Best-effort:
     * a publisher failure must not roll the cutover back.
     */
    protected function publishCutoverComplete(ImportServerMigration $migration, ImportSiteMigration $child): void
    {
        if ($this->publisher === null) {
            return;
        }
        $actor = User::find($migration->user_id);
        try {
            $this->publisher->publish(
                eventKey: 'import.migration.cutover_complete',
                subject: $migration,
                title: 'Cutover complete: '.$child->domain,
                body: 'Site is now live on dply. Take down the Ploi site when you are ready.',
                url: route('imports.ploi.migration.progress', $migration),
                metadata: [
                    'site_migration_id' => $child->id,
                    'domain' => $child->domain,
                    'migration_id' => $migration->id,
                ],
                actor: $actor,
            );
        } catch (\Throwable $e) {
            Log::warning('failed to publish import.migration.cutover_complete', [
                'site_id' => $child->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function maybeMarkMigrationComplete(ImportServerMigration $migration): void
    {
        $children = $migration->siteMigrations()->get();
        if ($children->isEmpty()) {
            return;
        }

        $allComplete = true;
        $anyFailed = false;
        foreach ($children as $c) {
            if ($c->status === ImportSiteMigration::STATUS_COMPLETED) {
                continue;
            }
            $allComplete = false;
            if (in_array($c->status, [ImportSiteMigration::STATUS_ABORTED, ImportSiteMigration::STATUS_CUTOVER_FAILED], true)) {
                $anyFailed = true;
            }
        }

        if ($allComplete) {
            $migration->status = ImportServerMigration::STATUS_COMPLETED;
            $migration->completed_at = Carbon::now();
            $migration->save();
        } elseif ($anyFailed && ! $this->anyPending($children)) {
            $migration->status = ImportServerMigration::STATUS_PARTIAL;
            $migration->completed_at = Carbon::now();
            $migration->save();
        }
    }

    protected function anyPending($children): bool
    {
        foreach ($children as $c) {
            if (in_array($c->status, [
                ImportSiteMigration::STATUS_PENDING,
                ImportSiteMigration::STATUS_STAGING,
                ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
                ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
            ], true)) {
                return true;
            }
        }

        return false;
    }
}
