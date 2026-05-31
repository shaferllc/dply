<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteCertificate;
use App\Models\SitePreviewDomain;
use App\Services\Sites\CaddySiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('edge tls front binds https only and reverse proxies backend port', function (): void {
    $site = Site::factory()->create([
        'type' => SiteType::Static,
        'document_root' => '/var/www/demo/public',
        'ssl_status' => Site::SSL_ACTIVE,
    ]);
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'sdfsfdsfds-d8d17ff4.ondply.com',
        'is_primary' => true,
    ]);
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'status' => SiteCertificate::STATUS_ACTIVE,
        'last_installed_at' => now(),
        'domains_json' => ['sdfsfdsfds-d8d17ff4.ondply.com'],
    ]);

    $config = app(CaddySiteConfigBuilder::class)->buildEdgeTlsFront($site->fresh(['previewDomains']), 28441);

    expect($config)
        ->toContain('https://sdfsfdsfds-d8d17ff4.ondply.com')
        ->toContain('/etc/letsencrypt/live/sdfsfdsfds-d8d17ff4.ondply.com/fullchain.pem')
        ->toContain('reverse_proxy 127.0.0.1:28441')
        ->not->toContain(':80');
});

test('edge tls front waits for installed certificate material', function (): void {
    $site = Site::factory()->create([
        'type' => SiteType::Static,
        'document_root' => '/var/www/demo/public',
        'ssl_status' => Site::SSL_PENDING,
    ]);
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'sdfsfdsfds-d8d17ff4.ondply.com',
        'is_primary' => true,
    ]);

    expect(app(CaddySiteConfigBuilder::class)->buildEdgeTlsFront($site->fresh(['previewDomains']), 28441))->toBe('');
});

test('edge tls front ignores ssl active without installed certificate record', function (): void {
    $site = Site::factory()->create([
        'type' => SiteType::Static,
        'ssl_status' => Site::SSL_ACTIVE,
    ]);
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'preview.example.test',
        'is_primary' => true,
    ]);

    expect(app(CaddySiteConfigBuilder::class)->buildEdgeTlsFront($site->fresh(['previewDomains']), 28441))->toBe('');
});

test('edge tls front enforces basic auth before reverse proxy', function (): void {
    $site = Site::factory()->create([
        'type' => SiteType::Static,
        'document_root' => '/var/www/demo/public',
        'ssl_status' => Site::SSL_ACTIVE,
    ]);
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'auth-preview.ondply.com',
        'is_primary' => true,
    ]);
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'status' => SiteCertificate::STATUS_ACTIVE,
        'last_installed_at' => now(),
        'domains_json' => ['auth-preview.ondply.com'],
    ]);
    $hash = Hash::make('secret');
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'preview',
        'password_hash' => $hash,
        'path' => '/',
    ]);

    $config = app(CaddySiteConfigBuilder::class)->buildEdgeTlsFront($site->fresh(['previewDomains', 'basicAuthUsers']), 28441);

    expect($config)
        ->toContain('basic_auth {')
        ->toContain('preview '.$hash)
        ->toContain('reverse_proxy 127.0.0.1:28441');
});

test('edge tls front is empty when site does not expect tls', function (): void {
    $site = Site::factory()->make([
        'type' => SiteType::Static,
        'ssl_status' => null,
    ]);
    $site->setRelation('previewDomains', new Collection);
    $site->setRelation('domains', new Collection);

    expect(app(CaddySiteConfigBuilder::class)->buildEdgeTlsFront($site, 20001))->toBe('');
});
