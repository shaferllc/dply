<?php

namespace App\Services\Certificates;

use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\SshConnection;
class ImportedCertificateInstaller implements CertificateEngine
{
    public function supports(SiteCertificate $certificate): bool
    {
        return $certificate->provider_type === SiteCertificate::PROVIDER_IMPORTED;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $site = $certificate->site()->with('server')->firstOrFail();
        $server = $site->server;

        $certificatePem = trim((string) $certificate->certificate_pem);
        $privateKeyPem = trim((string) $certificate->private_key_pem);
        if ($certificatePem === '' || $privateKeyPem === '') {
            throw new \InvalidArgumentException('A certificate and private key are required.');
        }

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_INSTALLING,
            'last_requested_at' => now(),
        ])->save();

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            $certificate->forceFill([
                'status' => SiteCertificate::STATUS_ACTIVE,
                'last_output' => 'Stored imported certificate metadata without host installation because SSH is unavailable.',
                'last_installed_at' => now(),
            ])->save();

            $site->update(['ssl_status' => Site::SSL_ACTIVE]);

            return $certificate->fresh();
        }

        $remoteDir = sprintf('/etc/dply/certs/%s', $site->id);
        $remoteBase = sprintf('%s/%s', $remoteDir, $certificate->id);
        $ssh = new SshConnection($server);
        $ssh->exec(sprintf('mkdir -p %s && chmod 700 %s', escapeshellarg($remoteDir), escapeshellarg($remoteDir)), 60);
        $ssh->putFile($remoteBase.'.crt', $certificatePem."\n");
        $ssh->putFile($remoteBase.'.key', $privateKeyPem."\n");

        if (trim((string) $certificate->chain_pem) !== '') {
            $ssh->putFile($remoteBase.'.chain.pem', trim((string) $certificate->chain_pem)."\n");
        }

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_ACTIVE,
            'certificate_path' => $remoteBase.'.crt',
            'private_key_path' => $remoteBase.'.key',
            'chain_path' => trim((string) $certificate->chain_pem) !== '' ? $remoteBase.'.chain.pem' : null,
            'last_output' => 'Imported certificate uploaded to the host.',
            'last_installed_at' => now(),
        ])->save();

        $site->update([
            'ssl_status' => Site::SSL_ACTIVE,
            'ssl_installed_at' => now(),
        ]);

        if ($certificate->scope_type === SiteCertificate::SCOPE_PREVIEW && $certificate->previewDomain) {
            $certificate->previewDomain->update([
                'ssl_status' => 'active',
                'last_ssl_checked_at' => now(),
            ]);
        }

        return $certificate->fresh();
    }
}
