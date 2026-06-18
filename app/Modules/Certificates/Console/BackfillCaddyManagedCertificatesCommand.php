<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Console;

use App\Models\Site;
use App\Models\SiteCertificate;
use App\Modules\Certificates\Services\CaddyAutomaticHttpsCertificateEngine;
use App\Modules\Certificates\Services\CertificateRequestService;
use Illuminate\Console\Command;

/**
 * Reconcile certificate records for Caddy-fronted sites that predate the
 * Caddy automatic-HTTPS certificate engine.
 *
 * Caddy terminates TLS with built-in automatic HTTPS — it obtains and renews
 * the Let's Encrypt cert itself, so certbot must never run on those boxes
 * (worker servers don't even have certbot installed). Before the engine
 * existed, every SSL attempt on a Caddy site shelled out to certbot, failed
 * ("certbot: command not found"), and left the site at ssl_status=failed —
 * sometimes with a stuck SiteCertificate row, sometimes (when the failure
 * happened before the row persisted) with none at all.
 *
 * This command brings those sites in line with how new sites behave now:
 *   1. Reconcile any existing Let's Encrypt HTTP rows by feeding them through
 *      CertificateRequestService::execute(), which — for a Caddy-fronted site —
 *      resolves to {@see CaddyAutomaticHttpsCertificateEngine}
 *      and marks them active/managed-by-Caddy with no SSH.
 *   2. Ensure a managed customer-domain cert exists (issueForCustomerDomains).
 *   3. Ensure the primary preview domain's auto-SSL cert exists.
 *
 * Every step is idempotent and guarded against duplicates, so re-running is a
 * no-op once a site is reconciled. No SSH is performed — Caddy already serves
 * (and renews) the live certificate; this only fixes dply's records + status.
 *
 * Edge-proxy layouts (Envoy/HAProxy/Traefik in front of a Caddy backend) and
 * headless sites (no HTTP front) are intentionally left alone.
 *
 * Examples:
 *   php artisan dply:caddy-certs:backfill --dry-run
 *   php artisan dply:caddy-certs:backfill --site=01kt4c6z...
 *   php artisan dply:caddy-certs:backfill --server=01kt2y64...
 */
class BackfillCaddyManagedCertificatesCommand extends Command
{
    protected $signature = 'dply:caddy-certs:backfill
                            {--site= : Limit to one site (ULID)}
                            {--server= : Limit to sites on one server (ULID)}
                            {--dry-run : List what would change without writing anything}';

    protected $description = 'Reconcile SSL records on Caddy-fronted sites to Caddy automatic-HTTPS (no certbot).';

    private bool $dryRun = false;

    public function handle(CertificateRequestService $certificateRequestService): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        if ($this->dryRun) {
            $this->warn('Dry run — no changes will be written.');
        }

        $query = Site::query()->with(['server', 'certificates', 'previewDomains', 'domains', 'domainAliases']);
        if ($siteId = $this->option('site')) {
            $query->where('id', $siteId);
        }
        if ($serverId = $this->option('server')) {
            $query->where('server_id', $serverId);
        }

        $sites = $query->orderBy('name')->get()
            ->filter(fn (Site $site): bool => $this->isEligible($site))
            ->values();

        if ($sites->isEmpty()) {
            $this->info('No Caddy-fronted sites match the filters.');

            return self::SUCCESS;
        }

        $totals = ['reconciled' => 0, 'customer' => 0, 'preview' => 0, 'untouched' => 0, 'failed' => 0];

        foreach ($sites as $site) {
            $changed = false;
            $this->line(sprintf('  %s (ssl_status=%s)', $site->name, $site->ssl_status));

            try {
                $changed = $this->reconcileExistingRows($site, $certificateRequestService, $totals)
                    || $this->ensureCustomerCertificate($site, $certificateRequestService, $totals)
                    || $this->ensurePreviewCertificate($site, $certificateRequestService, $totals);
            } catch (\Throwable $e) {
                $this->error(sprintf('    [fail] %s: %s', $site->name, $e->getMessage()));
                $totals['failed']++;

                continue;
            }

            if (! $changed) {
                $this->line('    [ok] already managed by Caddy — nothing to do');
                $totals['untouched']++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Done. reconciled=%d customer=%d preview=%d untouched=%d failed=%d eligible=%d',
            $totals['reconciled'],
            $totals['customer'],
            $totals['preview'],
            $totals['untouched'],
            $totals['failed'],
            $sites->count(),
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Caddy terminates TLS itself only when it's the primary front — not when
     * an edge proxy sits in front of a Caddy backend — and only for sites that
     * actually serve HTTP (headless worker hosts have no TLS).
     */
    private function isEligible(Site $site): bool
    {
        return $site->webserver() === 'caddy'
            && ! $site->server?->hasEdgeProxy()
            && ! $site->isHeadless();
    }

    /**
     * Re-run any non-managed Let's Encrypt HTTP rows through the resolver so the
     * Caddy engine re-stamps them active/managed-by-Caddy.
     *
     * @param  array<string, int>  $totals
     */
    private function reconcileExistingRows(Site $site, CertificateRequestService $service, array &$totals): bool
    {
        $rows = $site->certificates
            ->where('provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->where('challenge_type', SiteCertificate::CHALLENGE_HTTP)
            ->reject(fn (SiteCertificate $cert): bool => $this->alreadyManagedByCaddy($cert))
            ->reject(fn (SiteCertificate $cert): bool => $cert->status === SiteCertificate::STATUS_REMOVED);

        $changed = false;
        foreach ($rows as $cert) {
            $label = sprintf('reconcile %s [%s]', implode(', ', $cert->domainHostnames()), $cert->status);
            if ($this->dryRun) {
                $this->line('    [would] '.$label);
                $changed = true;

                continue;
            }
            $service->execute($cert);
            $this->info('    [ok] '.$label);
            $totals['reconciled']++;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Ensure a managed customer-domain certificate exists for the site's SSL
     * hostnames. Skipped when the site has no issuance hostnames or already has
     * a live (pending/active) customer cert.
     *
     * @param  array<string, int>  $totals
     */
    private function ensureCustomerCertificate(Site $site, CertificateRequestService $service, array &$totals): bool
    {
        if ($site->sslIssuanceHostnames() === []) {
            return false;
        }

        $hasLiveCustomer = $site->certificates
            ->where('scope_type', SiteCertificate::SCOPE_CUSTOMER)
            ->whereIn('status', [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_ACTIVE,
            ])
            ->isNotEmpty();

        if ($hasLiveCustomer) {
            return false;
        }

        $label = sprintf('customer cert %s', implode(', ', $site->sslIssuanceHostnames()));
        if ($this->dryRun) {
            $this->line('    [would] create '.$label);

            return true;
        }

        $service->issueForCustomerDomains($site);
        $this->info('    [ok] created '.$label);
        $totals['customer']++;

        return true;
    }

    /**
     * Ensure the primary preview domain's auto-SSL cert exists. queuePrimaryPreviewAutoSsl()
     * already returns null when a live preview cert exists, so this is idempotent.
     *
     * @param  array<string, int>  $totals
     */
    private function ensurePreviewCertificate(Site $site, CertificateRequestService $service, array &$totals): bool
    {
        $preview = $site->primaryPreviewDomain();
        if ($preview === null || ! $preview->auto_ssl || $preview->hostname === '') {
            return false;
        }

        if ($this->dryRun) {
            $hasLivePreview = $site->certificates
                ->where('scope_type', SiteCertificate::SCOPE_PREVIEW)
                ->where('preview_domain_id', $preview->id)
                ->whereIn('status', [
                    SiteCertificate::STATUS_PENDING,
                    SiteCertificate::STATUS_ISSUED,
                    SiteCertificate::STATUS_INSTALLING,
                    SiteCertificate::STATUS_ACTIVE,
                ])
                ->isNotEmpty();

            if ($hasLivePreview) {
                return false;
            }
            $this->line('    [would] create preview cert '.$preview->hostname);

            return true;
        }

        $cert = $service->queuePrimaryPreviewAutoSsl($site);
        if ($cert === null) {
            return false;
        }

        $service->execute($cert);
        $this->info('    [ok] created preview cert '.$preview->hostname);
        $totals['preview']++;

        return true;
    }

    private function alreadyManagedByCaddy(SiteCertificate $certificate): bool
    {
        $applied = $certificate->applied_settings ?? [];

        return $certificate->status === SiteCertificate::STATUS_ACTIVE
            && ($applied['managed_by'] ?? null) === 'caddy';
    }
}
