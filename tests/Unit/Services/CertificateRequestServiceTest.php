<?php

namespace Tests\Unit\Services;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Services\Certificates\CertificateEngineResolver;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Certificates\CertificateSigningRequestGenerator;
use App\Services\Certificates\ImportedCertificateInstaller;
use App\Services\Certificates\LetsEncryptDnsCertificateEngine;
use App\Services\Certificates\LetsEncryptHttpCertificateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_domain_requests_do_not_include_preview_domains(): void
    {
        $site = Site::factory()->create();
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.com',
            'is_primary' => true,
        ]);
        SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview.dply.cc',
            'is_primary' => true,
            'dns_status' => 'ready',
        ]);

        $service = $this->service();
        $certificate = $service->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_CSR,
            'challenge_type' => SiteCertificate::CHALLENGE_MANUAL,
            'domains_json' => $site->customerDomainHostnames(),
            'status' => SiteCertificate::STATUS_PENDING,
        ]);

        $this->assertSame(['app.example.com'], $certificate->domainHostnames());
        $this->assertNotContains('preview.dply.cc', $certificate->domainHostnames());
    }

    public function test_auto_ssl_targets_only_the_primary_preview_domain_once(): void
    {
        $site = Site::factory()->create();
        $primary = SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview-primary.dply.cc',
            'is_primary' => true,
            'auto_ssl' => true,
            'dns_status' => 'ready',
        ]);
        SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview-secondary.dply.cc',
            'is_primary' => false,
            'auto_ssl' => true,
            'dns_status' => 'ready',
        ]);

        $service = $this->service();
        $first = $service->queuePrimaryPreviewAutoSsl($site->fresh(['previewDomains']));
        $second = $service->queuePrimaryPreviewAutoSsl($site->fresh(['previewDomains']));

        $this->assertNotNull($first);
        $this->assertSame([$primary->hostname], $first->domainHostnames());
        $this->assertNull($second);
    }

    public function test_imported_certificate_can_be_stored_without_ssh_install(): void
    {
        $server = Server::factory()->create([
            'status' => Server::STATUS_READY,
            'ssh_private_key' => null,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
        ]);

        $certificate = $this->service()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_IMPORTED,
            'challenge_type' => SiteCertificate::CHALLENGE_IMPORTED,
            'domains_json' => ['app.example.com'],
            'certificate_pem' => "-----BEGIN CERTIFICATE-----\nabc\n-----END CERTIFICATE-----",
            'private_key_pem' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
            'status' => SiteCertificate::STATUS_PENDING,
        ]);

        $stored = $this->service()->execute($certificate);

        $this->assertSame(SiteCertificate::STATUS_ACTIVE, $stored->status);
        $this->assertStringContainsString('without host installation', (string) $stored->last_output);
    }

    public function test_csr_generation_stores_key_material(): void
    {
        $site = Site::factory()->create();
        $certificate = $this->service()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_CSR,
            'challenge_type' => SiteCertificate::CHALLENGE_MANUAL,
            'domains_json' => ['app.example.com'],
            'status' => SiteCertificate::STATUS_PENDING,
        ]);

        $generated = $this->service()->execute($certificate);

        $this->assertSame(SiteCertificate::STATUS_ISSUED, $generated->status);
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) $generated->csr_pem);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', (string) $generated->private_key_pem);
    }

    private function service(): CertificateRequestService
    {
        return new CertificateRequestService(new CertificateEngineResolver([
            new LetsEncryptHttpCertificateEngine,
            new LetsEncryptDnsCertificateEngine,
            new ImportedCertificateInstaller,
            new CertificateSigningRequestGenerator,
        ]));
    }
}
