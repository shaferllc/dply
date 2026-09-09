<?php

declare(strict_types=1);

namespace Tests\Feature\Certificates;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\User;
use App\Modules\Certificates\Services\CertificateRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lineageDedupeSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm', 'webserver' => 'nginx'],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
}

function lineageDedupeCertificate(Site $site, array $overrides = []): SiteCertificate
{
    return SiteCertificate::query()->create(array_merge([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => ['acme.example.com'],
        'status' => SiteCertificate::STATUS_FAILED,
        'requested_settings' => ['source' => 'tenant_ssl'],
    ], $overrides));
}

test('a failed request for the same hostname is reused rather than duplicated', function (): void {
    $site = lineageDedupeSite();
    $failed = lineageDedupeCertificate($site);

    $reusable = app(CertificateRequestService::class)->findReusable(
        $site,
        ['acme.example.com'],
        SiteCertificate::PROVIDER_LETSENCRYPT,
        SiteCertificate::CHALLENGE_HTTP,
        'tenant_ssl',
    );

    expect($reusable?->id)->toBe($failed->id);
});

test('domain matching ignores case and ordering', function (): void {
    $site = lineageDedupeSite();
    $existing = lineageDedupeCertificate($site, [
        'domains_json' => ['acme.example.com', 'www.acme.example.com'],
    ]);

    $reusable = app(CertificateRequestService::class)->findReusable(
        $site,
        ['WWW.Acme.Example.com', 'acme.example.com'],
        SiteCertificate::PROVIDER_LETSENCRYPT,
        SiteCertificate::CHALLENGE_HTTP,
        'tenant_ssl',
    );

    expect($reusable?->id)->toBe($existing->id);
});

test('a different san set is not reused', function (): void {
    $site = lineageDedupeSite();

    // Adding www is a genuinely different certificate request, not a repeat of
    // the apex-only one.
    lineageDedupeCertificate($site, ['domains_json' => ['acme.example.com']]);

    $reusable = app(CertificateRequestService::class)->findReusable(
        $site,
        ['acme.example.com', 'www.acme.example.com'],
        SiteCertificate::PROVIDER_LETSENCRYPT,
        SiteCertificate::CHALLENGE_HTTP,
        'tenant_ssl',
    );

    expect($reusable)->toBeNull();
});

test('removed rows are never reused', function (): void {
    $site = lineageDedupeSite();
    lineageDedupeCertificate($site, ['status' => SiteCertificate::STATUS_REMOVED]);

    $reusable = app(CertificateRequestService::class)->findReusable(
        $site,
        ['acme.example.com'],
        SiteCertificate::PROVIDER_LETSENCRYPT,
        SiteCertificate::CHALLENGE_HTTP,
        'tenant_ssl',
    );

    expect($reusable)->toBeNull();
});

test('a different source does not collide', function (): void {
    $site = lineageDedupeSite();
    lineageDedupeCertificate($site, ['requested_settings' => ['source' => 'customer_domains']]);

    $reusable = app(CertificateRequestService::class)->findReusable(
        $site,
        ['acme.example.com'],
        SiteCertificate::PROVIDER_LETSENCRYPT,
        SiteCertificate::CHALLENGE_HTTP,
        'tenant_ssl',
    );

    expect($reusable)->toBeNull();
});
