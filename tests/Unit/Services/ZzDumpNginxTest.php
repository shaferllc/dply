<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Services\Sites\NginxSiteConfigBuilder;

test('dump nginx', function () {
    $site = Site::factory()->create([
        'slug' => 'mixed-tls',
        'type' => SiteType::Php,
        'document_root' => '/var/www/mixed-tls/public',
        'repository_path' => '/var/www/mixed-tls',
        'ssl_status' => Site::SSL_ACTIVE,
    ]);
    SitePreviewDomain::query()->create(['site_id' => $site->id, 'hostname' => 'mixed-tls.on-dply.com', 'is_primary' => true]);
    SiteDomain::query()->create(['site_id' => $site->id, 'hostname' => 'plain.example.com', 'is_primary' => false]);
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'status' => SiteCertificate::STATUS_ACTIVE,
        'last_installed_at' => now(),
        'domains_json' => ['mixed-tls.on-dply.com'],
    ]);

    $site->refresh()->load('domains', 'redirects', 'previewDomains');
    file_put_contents(
        '/private/tmp/claude-502/-Users-tomshafer-Projects-Apps-dply-project-dply/4935f1f7-9842-4dbf-9078-feb9b1af6a7c/scratchpad/out.conf',
        app(NginxSiteConfigBuilder::class)->build($site)
    );

    expect(true)->toBeTrue();
});
