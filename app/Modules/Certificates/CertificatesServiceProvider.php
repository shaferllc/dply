<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

use App\Modules\Certificates\Console\BackfillCaddyManagedCertificatesCommand;
use App\Modules\Certificates\Console\BackfillServerWildcardCertificatesCommand;
use App\Modules\Certificates\Console\RenewServerWildcardCertificatesCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Certificates module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The TLS/cert engine: ACME/ZeroSSL/Caddy engines, CSR/issuance/repair services,
 * wildcard issuer, and ServerCertificateInventory (extracted from Services\Servers
 * by capability). Re-registers the backfill/renew commands. Jobs dispatch by class.
 * The SiteCertificate/ServerWildcardCertificate models stay in app/Models; the
 * Sites cert UI concerns stay in the shell.
 */
class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillCaddyManagedCertificatesCommand::class,
                BackfillServerWildcardCertificatesCommand::class,
                RenewServerWildcardCertificatesCommand::class,
            ]);
        }
    }
}
