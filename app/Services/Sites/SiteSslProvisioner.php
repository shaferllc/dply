<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Certificates\CertificateRequestService;

class SiteSslProvisioner
{
    public function __construct(
        private readonly CertificateRequestService $certificateRequestService,
    ) {}

    public function provision(Site $site, ?string $email = null): string
    {
        $site->update(['ssl_status' => Site::SSL_PENDING]);

        $certificate = $this->certificateRequestService->issueForCustomerDomains($site);

        return (string) ($certificate->last_output ?? 'SSL certificate requested.');
    }
}
