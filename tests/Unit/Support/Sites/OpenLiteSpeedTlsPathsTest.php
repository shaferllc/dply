<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SitePreviewDomain;
use App\Models\User;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ols tls paths resolve from preview hostname', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['webserver' => 'openlitespeed'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'sdfsfdsfds-d8d17ff4.ondply.com',
        'is_primary' => true,
    ]);

    $paths = OpenLiteSpeedTlsPaths::resolve($site->fresh());

    expect($paths)->toMatchArray([
        'keyFile' => '/etc/letsencrypt/live/sdfsfdsfds-d8d17ff4.ondply.com/privkey.pem',
        'certFile' => '/etc/letsencrypt/live/sdfsfdsfds-d8d17ff4.ondply.com/fullchain.pem',
    ]);
});

test('ols tls paths prefer active certificate domains', function (): void {
    $site = Site::factory()->create();
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'status' => SiteCertificate::STATUS_ACTIVE,
        'domains_json' => ['app.example.com', 'www.app.example.com'],
    ]);

    $paths = OpenLiteSpeedTlsPaths::resolve($site->fresh());

    expect($paths['certFile'])->toBe('/etc/letsencrypt/live/app.example.com/fullchain.pem');
});
