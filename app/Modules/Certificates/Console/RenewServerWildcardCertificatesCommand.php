<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Console;

use App\Modules\Certificates\Jobs\IssueServerWildcardCertificateJob;
use App\Models\ServerWildcardCertificate;
use Illuminate\Console\Command;

/**
 * Renew per-(server, zone) wildcard TLS certificates (e.g. *.on-dply.com)
 * before they expire. Issuance goes through {@see IssueServerWildcardCertificateJob}
 * — which re-supplies the certbot --manual DNS-01 hooks — rather than a bare
 * `certbot renew` (the box can't re-run the DNS hooks without the creds file).
 *
 * Idempotent: the job is concurrency-locked and gated on
 * {@see ServerWildcardCertificate::needsIssuance()}, so re-running is cheap.
 *
 * Examples:
 *   php artisan dply:wildcards:renew
 *   php artisan dply:wildcards:renew --within=45 --dry-run
 */
class RenewServerWildcardCertificatesCommand extends Command
{
    protected $signature = 'dply:wildcards:renew
                            {--within=30 : Renew certs expiring within this many days}
                            {--dry-run : List what would be renewed without dispatching}';

    protected $description = 'Renew per-server wildcard TLS certificates nearing expiry.';

    public function handle(): int
    {
        $within = max(1, (int) $this->option('within'));
        $dryRun = (bool) $this->option('dry-run');

        // Mark long-expired, unreplaced certs so the UI/coverage reflects reality.
        ServerWildcardCertificate::query()
            ->where('status', ServerWildcardCertificate::STATUS_ACTIVE)
            ->whereNotNull('not_after')
            ->where('not_after', '<', now())
            ->update(['status' => ServerWildcardCertificate::STATUS_EXPIRED]);

        $candidates = ServerWildcardCertificate::query()
            ->whereIn('status', [
                ServerWildcardCertificate::STATUS_ACTIVE,
                ServerWildcardCertificate::STATUS_EXPIRED,
                ServerWildcardCertificate::STATUS_FAILED,
            ])
            ->get()
            ->filter(fn (ServerWildcardCertificate $w): bool => $w->needsIssuance($within));

        if ($candidates->isEmpty()) {
            $this->info('No wildcard certificates need renewal.');

            return self::SUCCESS;
        }

        foreach ($candidates as $wildcard) {
            $this->line(sprintf(
                '%s *.%s on server %s (status=%s, expires=%s)',
                $dryRun ? '[dry-run] would renew' : 'renewing',
                $wildcard->zone,
                $wildcard->server_id,
                $wildcard->status,
                $wildcard->not_after?->toDateString() ?? 'unknown',
            ));

            if (! $dryRun) {
                IssueServerWildcardCertificateJob::dispatch((string) $wildcard->server_id, (string) $wildcard->zone);
            }
        }

        $this->info(sprintf('%s %d wildcard certificate(s).', $dryRun ? 'Would renew' : 'Dispatched renewal for', $candidates->count()));

        return self::SUCCESS;
    }
}
