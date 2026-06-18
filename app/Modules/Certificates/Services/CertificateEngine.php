<?php

namespace App\Modules\Certificates\Services;

use App\Models\SiteCertificate;

interface CertificateEngine
{
    public function supports(SiteCertificate $certificate): bool;

    public function execute(SiteCertificate $certificate): SiteCertificate;
}
