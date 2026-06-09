<?php

declare(strict_types=1);

namespace Tests\Feature\SiteProvisionerPreviewSslTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Models\User;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('check readiness queues preview ssl only when preview hostname is reachable', function () {
    Queue::fake();
    Http::fake([
        'http://localhost' => Http::response('ok', 200),
    ]);

    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ip_address' => '127.0.0.1',
        'ssh_user' => 'forge',
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'customer-unreachable.invalid',
        'is_primary' => true,
    ]);

    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'localhost',
        'is_primary' => true,
        'auto_ssl' => true,
        'dns_status' => 'ready',
    ]);

    $result = app(SiteProvisioner::class)->checkReadiness($site->fresh(['server', 'domains', 'previewDomains']));

    expect($result['ok'])->toBeTrue()
        ->and($result['hostname'])->toBe('localhost');

    $certificate = SiteCertificate::query()
        ->where('site_id', $site->id)
        ->where('scope_type', SiteCertificate::SCOPE_PREVIEW)
        ->first();

    expect($certificate)->not->toBeNull('Preview SSL should be queued when the preview hostname is reachable.');
    expect($certificate->domainHostnames())->toBe(['localhost']);
});

test('check readiness skips preview ssl when only customer domain is reachable', function () {
    Queue::fake();
    Http::fake([
        'http://localhost' => Http::response('ok', 200),
    ]);

    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ip_address' => '127.0.0.1',
        'ssh_user' => 'forge',
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'localhost',
        'is_primary' => true,
    ]);

    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'preview-invalid.invalid',
        'is_primary' => true,
        'auto_ssl' => true,
        'dns_status' => 'ready',
    ]);

    $result = app(SiteProvisioner::class)->checkReadiness($site->fresh(['server', 'domains', 'previewDomains']));

    expect($result['ok'])->toBeTrue()
        ->and($result['hostname'])->toBe('localhost');

    expect(SiteCertificate::query()->where('site_id', $site->id)->count())->toBe(0);
});
