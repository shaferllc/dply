<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Jobs\IssueSiteSslJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Services\Imports\SourceSshConnectionFactory;
use App\Services\SshConnectionFactory;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Selects the per-site SSL strategy (Clean / Bridged / Gap) per Q9b, then
 * sets it up so cutover doesn't introduce an HTTPS error window.
 *
 *   - Clean: org has DNS credentials covering the site's domain → issue via
 *     DNS-01 pre-cutover. dply's existing SSL machinery handles this once the
 *     site has a usable cert request marker; we just stash the strategy and
 *     queue the issuance via the standard SiteCertificate path.
 *
 *   - Bridged: no DNS automation, but Ploi has a Let's Encrypt cert with >7d
 *     validity → rsync the cert+key from Ploi over to dply via the ephemeral
 *     SSH key, install on nginx. Schedule re-issuance via HTTP-01 within 24h
 *     post-cutover (the orchestrator picks it up after cutover_smoke_test).
 *
 *   - Gap: neither path available → defer issuance to HTTP-01 right after
 *     DNS swap. Surfaces a clear warning in result_data so the cutover UI
 *     shows the user the expected ~30–120s HTTPS gap.
 */
class SetupSslHandler extends SshDependentHandler
{
    public function __construct(
        protected SshConnectionFactory $factory,
        protected SourceSshConnectionFactory $sourceFactory,
    ) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_SETUP_SSL;
    }

    protected function executeOnReadyServer(
        ImportMigrationStep $step,
        ImportServerMigration $migration,
        Server $target,
    ): void {
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('setup_ssl requires a target_site_id.');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing.');
        }

        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $strategy = $this->pickStrategy($credential, $migration, $child, $site);
        $child->ssl_strategy = $strategy;
        $child->save();

        match ($strategy) {
            ImportSiteMigration::SSL_CLEAN => $this->primeCleanStrategy($site, $step),
            ImportSiteMigration::SSL_BRIDGED => $this->bridgeFromPloi($site, $migration, $child, $target, $step),
            ImportSiteMigration::SSL_GAP => $this->deferToHttp01($site, $step),
            default => throw new RuntimeException('Unknown SSL strategy: '.$strategy),
        };
    }

    /**
     * Decide which strategy applies given current dply org state + Ploi cert state.
     */
    protected function pickStrategy(
        ProviderCredential $credential,
        ImportServerMigration $migration,
        ImportSiteMigration $child,
        Site $site,
    ): string {
        $orgId = $credential->organization_id;
        $hasDnsAutomation = ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->exists();

        if ($hasDnsAutomation) {
            return ImportSiteMigration::SSL_CLEAN;
        }

        // Look at Ploi cert state for the site.
        $driver = app(\App\Services\Imports\SourceDriverFactory::class)->for($credential);
        $cert = $driver->fetchSiteCertificate($migration->source_server_id, $child->source_site_id);
        if ($cert !== null
            && $cert['status'] === 'active'
            && $cert['issuer'] !== null
            && stripos($cert['issuer'], 'letsencrypt') !== false
            && $cert['valid_until'] !== null
            && Carbon::parse($cert['valid_until'])->isAfter(now()->addDays(7))
        ) {
            return ImportSiteMigration::SSL_BRIDGED;
        }

        return ImportSiteMigration::SSL_GAP;
    }

    /**
     * Mark the site eligible for issuance and dispatch the existing dply
     * SSL job, which handles DNS-01 issuance when a DNS-capable
     * ProviderCredential is available on the org (it auto-selects DNS-01
     * vs HTTP-01 internally based on what's connected).
     */
    protected function primeCleanStrategy(Site $site, ImportMigrationStep $step): void
    {
        $site->ssl_status = Site::SSL_PENDING;
        $site->save();
        IssueSiteSslJob::dispatch($site->id);
        $step->result_data = [
            'strategy' => 'clean',
            'detail' => 'dispatched IssueSiteSslJob — DNS-01 issuance against connected DNS credentials',
        ];
        $step->save();
    }

    /**
     * Pull cert + private key from Ploi's /etc/letsencrypt/live/{domain} (the
     * standard certbot layout), install on the dply nginx site. The private key
     * is transient on the orchestrator process — base64-decoded into a string,
     * pushed to dply via the existing RemoteShell::putFile, never persisted.
     */
    protected function bridgeFromPloi(
        Site $site,
        ImportServerMigration $migration,
        ImportSiteMigration $child,
        Server $target,
        ImportMigrationStep $step,
    ): void {
        $domain = $child->domain;
        $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        $keyPath = "/etc/letsencrypt/live/{$domain}/privkey.pem";

        $ploiSsh = $this->sourceFactory->forMigration($migration);
        $certB64 = trim($ploiSsh->exec('sudo cat '.escapeshellarg($certPath).' | base64 -w0'));
        $keyB64 = trim($ploiSsh->exec('sudo cat '.escapeshellarg($keyPath).' | base64 -w0'));

        if ($certB64 === '' || $keyB64 === '') {
            throw new RuntimeException('Could not read certificate or key from Ploi at '.$certPath);
        }

        $cert = base64_decode($certB64, strict: true);
        $key = base64_decode($keyB64, strict: true);
        if ($cert === false || $key === false) {
            throw new RuntimeException('Base64 decode failed for cert/key transfer.');
        }

        $dplyCertDir = "/etc/letsencrypt/live/{$domain}";
        $dplyShell = $this->factory->forServer($target);
        $dplyShell->exec('mkdir -p '.escapeshellarg($dplyCertDir));
        $dplyShell->putFile($dplyCertDir.'/fullchain.pem', $cert);
        $dplyShell->putFile($dplyCertDir.'/privkey.pem', $key);
        $dplyShell->exec('chmod 600 '.escapeshellarg($dplyCertDir.'/privkey.pem'));
        $dplyShell->exec('chmod 644 '.escapeshellarg($dplyCertDir.'/fullchain.pem'));

        $site->ssl_status = Site::SSL_ACTIVE;
        $site->save();

        $step->result_data = [
            'strategy' => 'bridged',
            'cert_path' => $dplyCertDir.'/fullchain.pem',
            'note' => 're-issuance via HTTP-01 will run within 24h post-cutover',
        ];
        $step->save();
    }

    protected function deferToHttp01(Site $site, ImportMigrationStep $step): void
    {
        $site->ssl_status = Site::SSL_NONE;
        $site->save();
        // No immediate dispatch — IssueSiteSslJob is queued from the cutover
        // smoke-test step's success path (see CutoverSmokeTestHandler).
        $step->result_data = [
            'strategy' => 'gap',
            'note' => 'No DNS automation and no usable LE cert on Ploi; HTTPS issuance happens immediately after DNS swap (~30–120s gap).',
        ];
        $step->save();
    }
}
