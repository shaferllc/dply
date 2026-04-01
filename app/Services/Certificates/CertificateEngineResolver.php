<?php

namespace App\Services\Certificates;

use App\Models\SiteCertificate;

class CertificateEngineResolver
{
    /**
     * @param  iterable<int, CertificateEngine>  $engines
     */
    public function __construct(
        private readonly iterable $engines,
    ) {}

    public function for(SiteCertificate $certificate): CertificateEngine
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($certificate)) {
                return $engine;
            }
        }

        throw new \RuntimeException('No certificate engine is available for the selected provider and challenge.');
    }
}
