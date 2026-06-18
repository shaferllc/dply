<?php

namespace Tests\Unit\Services\CertificateRequestServiceTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Modules\Certificates\Services\CertificateEngine;
use App\Modules\Certificates\Services\CertificateEngineResolver;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Modules\Certificates\Services\CertificateSigningRequestGenerator;
use App\Modules\Certificates\Services\ImportedCertificateInstaller;
use App\Modules\Certificates\Services\LetsEncryptDnsCertificateEngine;
use App\Modules\Certificates\Services\LetsEncryptHttpCertificateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('issue for customer domains includes domain aliases in domains json', function () {
    $site = Site::factory()->create();
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'is_primary' => true,
    ]);
    SiteDomainAlias::query()->create([
        'site_id' => $site->id,
        'hostname' => 'alias.example.com',
        'label' => 'marketing',
    ]);

    $noopEngine = new class implements CertificateEngine
    {
        public function supports(SiteCertificate $certificate): bool
        {
            return $certificate->provider_type === SiteCertificate::PROVIDER_LETSENCRYPT
                && $certificate->challenge_type === SiteCertificate::CHALLENGE_HTTP;
        }

        public function execute(SiteCertificate $certificate): SiteCertificate
        {
            $certificate->forceFill([
                'status' => SiteCertificate::STATUS_ACTIVE,
                'last_output' => 'noop',
            ])->save();

            return $certificate->fresh();
        }
    };

    $service = new CertificateRequestService(new CertificateEngineResolver([$noopEngine]));
    $issued = $service->issueForCustomerDomains($site->fresh(['domains', 'domainAliases']));

    expect($issued->domainHostnames())->toBe(['app.example.com', 'alias.example.com']);
});

test('customer domain requests do not include preview domains', function () {
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

    $service = service();
    $certificate = $service->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_CSR,
        'challenge_type' => SiteCertificate::CHALLENGE_MANUAL,
        'domains_json' => $site->customerDomainHostnames(),
        'status' => SiteCertificate::STATUS_PENDING,
    ]);

    expect($certificate->domainHostnames())->toBe(['app.example.com']);
    expect($certificate->domainHostnames())->not->toContain('preview.dply.cc');
});

test('auto ssl targets only the primary preview domain once', function () {
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

    $service = service();
    $first = $service->queuePrimaryPreviewAutoSsl($site->fresh(['previewDomains']));
    $second = $service->queuePrimaryPreviewAutoSsl($site->fresh(['previewDomains']));

    expect($first)->not->toBeNull();
    expect($first->domainHostnames())->toBe([$primary->hostname]);
    expect($second)->toBeNull();
});

test('auto ssl reuses a failed preview certificate instead of creating duplicates', function () {
    $site = Site::factory()->create();
    $primary = SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'preview-primary.dply.cc',
        'is_primary' => true,
        'auto_ssl' => true,
        'dns_status' => 'ready',
    ]);

    $failed = SiteCertificate::query()->create([
        'site_id' => $site->id,
        'preview_domain_id' => $primary->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => [$primary->hostname],
        'status' => SiteCertificate::STATUS_FAILED,
        'last_output' => 'certbot failed',
    ]);

    $service = service();
    $retried = $service->queuePrimaryPreviewAutoSsl($site->fresh(['previewDomains']));

    expect($retried)->not->toBeNull()
        ->and($retried->id)->toBe($failed->id)
        ->and($retried->status)->toBe(SiteCertificate::STATUS_PENDING)
        ->and($retried->last_output)->toBeNull();
    expect(SiteCertificate::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('imported certificate can be stored without ssh install', function () {
    $server = Server::factory()->create([
        'status' => Server::STATUS_READY,
        'ssh_private_key' => null,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
    ]);

    $certificate = service()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_IMPORTED,
        'challenge_type' => SiteCertificate::CHALLENGE_IMPORTED,
        'domains_json' => ['app.example.com'],
        'certificate_pem' => "-----BEGIN CERTIFICATE-----\nabc\n-----END CERTIFICATE-----",
        'private_key_pem' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
        'status' => SiteCertificate::STATUS_PENDING,
    ]);

    $stored = service()->execute($certificate);

    expect($stored->status)->toBe(SiteCertificate::STATUS_ACTIVE);
    $this->assertStringContainsString('without host installation', (string) $stored->last_output);
});

test('csr generation stores key material', function () {
    $site = Site::factory()->create();
    $certificate = service()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_CSR,
        'challenge_type' => SiteCertificate::CHALLENGE_MANUAL,
        'domains_json' => ['app.example.com'],
        'status' => SiteCertificate::STATUS_PENDING,
    ]);

    $generated = service()->execute($certificate);

    expect($generated->status)->toBe(SiteCertificate::STATUS_ISSUED);
    $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) $generated->csr_pem);
    $this->assertStringContainsString('BEGIN PRIVATE KEY', (string) $generated->private_key_pem);
});

function service(): CertificateRequestService
{
    return new CertificateRequestService(new CertificateEngineResolver([
        new LetsEncryptHttpCertificateEngine,
        new LetsEncryptDnsCertificateEngine,
        new ImportedCertificateInstaller,
        new CertificateSigningRequestGenerator,
    ]));
}
