<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Jobs\ExecuteSiteCertificateJob;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\Sites\SiteWebserverConfigApplier;

/**
 * Re-applies webserver/ACME routing then re-queues a failed certificate request.
 */
final class CertificateRepairService
{
    public function __construct(
        private readonly SiteWebserverConfigApplier $webserverConfigApplier,
    ) {}

    public function repair(Site $site, SiteCertificate $certificate, ?string $userId = null): SiteCertificate
    {
        if (! in_array($certificate->status, [
            SiteCertificate::STATUS_FAILED,
            SiteCertificate::STATUS_EXPIRED,
        ], true)) {
            throw new \InvalidArgumentException('Only failed or expired certificates can be repaired.');
        }

        $site->loadMissing('server');

        if ($site->server?->isReady()) {
            $this->webserverConfigApplier->apply($site);
        }

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_PENDING,
            'last_output' => null,
        ])->save();

        ExecuteSiteCertificateJob::dispatch((string) $certificate->id, $userId);

        if ($site->ssl_status === Site::SSL_FAILED || $site->ssl_status === Site::SSL_NONE) {
            $site->update(['ssl_status' => Site::SSL_PENDING]);
        }

        return $certificate->fresh();
    }
}
