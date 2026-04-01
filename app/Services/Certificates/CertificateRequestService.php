<?php

namespace App\Services\Certificates;

use App\Models\Site;
use App\Models\SiteCertificate;

class CertificateRequestService
{
    public function __construct(
        private readonly CertificateEngineResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SiteCertificate
    {
        /** @var SiteCertificate $certificate */
        $certificate = SiteCertificate::query()->create($attributes);

        return $certificate;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $certificate->loadMissing(['site.server', 'previewDomain', 'providerCredential']);

        return $this->resolver->for($certificate)->execute($certificate);
    }

    public function issueForCustomerDomains(Site $site): SiteCertificate
    {
        $site->loadMissing('domains');

        return $this->execute($this->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => $site->customerDomainHostnames(),
            'status' => SiteCertificate::STATUS_PENDING,
            'requested_settings' => [
                'source' => 'customer_domains',
            ],
        ]));
    }

    public function queuePrimaryPreviewAutoSsl(Site $site): ?SiteCertificate
    {
        $previewDomain = $site->primaryPreviewDomain();
        if (! $previewDomain || ! $previewDomain->auto_ssl || $previewDomain->hostname === '') {
            return null;
        }

        $existing = SiteCertificate::query()
            ->where('site_id', $site->id)
            ->where('preview_domain_id', $previewDomain->id)
            ->where('scope_type', SiteCertificate::SCOPE_PREVIEW)
            ->whereIn('status', [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_ACTIVE,
            ])
            ->exists();

        if ($existing) {
            return null;
        }

        return $this->create([
            'site_id' => $site->id,
            'preview_domain_id' => $previewDomain->id,
            'scope_type' => SiteCertificate::SCOPE_PREVIEW,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => [$previewDomain->hostname],
            'status' => SiteCertificate::STATUS_PENDING,
            'requested_settings' => [
                'source' => 'preview_auto_ssl',
            ],
        ]);
    }

    public function removeArtifacts(SiteCertificate $certificate): void
    {
        $certificate->loadMissing('site.server');
        if ($certificate->certificate_path || $certificate->private_key_path || $certificate->chain_path) {
            try {
                $ssh = new \App\Services\SshConnection($certificate->site->server);
                foreach ([$certificate->certificate_path, $certificate->private_key_path, $certificate->chain_path] as $path) {
                    if (is_string($path) && $path !== '') {
                        $ssh->exec('rm -f '.escapeshellarg($path), 60);
                    }
                }
            } catch (\Throwable) {
                // Best effort. Site delete/cancel will still remove local records.
            }
        }

        $certificate->update([
            'status' => SiteCertificate::STATUS_REMOVED,
        ]);
    }
}
