<?php

namespace App\Services\Certificates;

use App\Models\SiteCertificate;

class CertificateSigningRequestGenerator implements CertificateEngine
{
    public function supports(SiteCertificate $certificate): bool
    {
        return $certificate->provider_type === SiteCertificate::PROVIDER_CSR;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $domains = $certificate->domainHostnames();
        if ($domains === []) {
            throw new \InvalidArgumentException('At least one domain is required to generate a CSR.');
        }

        $dn = ['commonName' => $domains[0]];
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException('Failed to generate a private key.');
        }

        $csr = openssl_csr_new($dn, $privateKey, [
            'digest_alg' => 'sha256',
        ]);

        if ($csr === false) {
            throw new \RuntimeException('Failed to generate a certificate signing request.');
        }

        openssl_pkey_export($privateKey, $privateKeyPem);
        openssl_csr_export($csr, $csrPem);

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_ISSUED,
            'private_key_pem' => $privateKeyPem,
            'csr_pem' => $csrPem,
            'last_output' => 'CSR generated.',
            'last_requested_at' => now(),
        ])->save();

        return $certificate->fresh();
    }
}
