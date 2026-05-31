<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteDomain;
use App\Services\Sites\SiteAccessGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function accessGateTestSite(): Site
{
    $site = Site::factory()->create([
        'slug' => 'gate-service',
        'type' => SiteType::Static,
        'document_root' => '/var/www/gate-service/public',
        'repository_path' => '/var/www/gate-service',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'gate.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    return $site->refresh()->load('domains');
}

test('add form gate password persists verifier and marks basic auth users for removal', function (): void {
    $site = accessGateTestSite();
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'legacy',
        'password_hash' => Hash::make('old'),
        'path' => '/',
    ]);

    $row = app(SiteAccessGateService::class)->addFormGatePassword($site, 'Sarah', 'newpassword1');

    expect($row->label)->toBe('Sarah')
        ->and($row->password_verifier)->toBe(hash('sha256', $row->password_salt.'newpassword1'));

    $gate = SiteAccessGate::query()->where('site_id', $site->id)->first();
    expect($gate)->not->toBeNull()
        ->and($gate->method)->toBe(SiteAccessGate::METHOD_FORM_PASSWORD);

    $site->refresh()->load('basicAuthUsers');
    expect($site->basicAuthUsers->first()->pending_removal_at)->not->toBeNull();
});

test('add form gate password rotates cookie secret', function (): void {
    $site = accessGateTestSite();
    $service = app(SiteAccessGateService::class);

    $service->addFormGatePassword($site, 'First', 'firstpass1');
    $firstSecret = SiteAccessGate::query()->where('site_id', $site->id)->value('cookie_secret');

    $service->addFormGatePassword($site->refresh(), 'Second', 'secondpass1');
    $secondSecret = SiteAccessGate::query()->where('site_id', $site->id)->value('cookie_secret');

    expect($secondSecret)->not->toBe($firstSecret);
});

test('add form gate password rejects short passwords', function (): void {
    $site = accessGateTestSite();

    app(SiteAccessGateService::class)->addFormGatePassword($site, 'Sarah', 'short');
})->throws(ValidationException::class);

test('disable clears form secrets and marks basic auth for removal', function (): void {
    $site = accessGateTestSite();
    $service = app(SiteAccessGateService::class);
    $service->addFormGatePassword($site, 'Sarah', 'gatepassword1');

    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'still-here',
        'password_hash' => Hash::make('x'),
        'path' => '/',
        'pending_removal_at' => null,
    ]);

    $service->disable($site->refresh());

    $gate = SiteAccessGate::query()->where('site_id', $site->id)->first();
    expect($gate)->not->toBeNull()
        ->and($gate->method)->toBe(SiteAccessGate::METHOD_OFF);

    expect(SiteAccessGatePassword::query()->where('site_id', $site->id)->whereNull('pending_removal_at')->count())->toBe(0);

    $site->refresh()->load('basicAuthUsers');
    expect($site->basicAuthUsers->every(fn ($u) => $u->pending_removal_at !== null))->toBeTrue();
});

test('config payload returns null when gate inactive', function (): void {
    $site = accessGateTestSite();

    expect(app(SiteAccessGateService::class)->configPayload($site))->toBeNull();
});

test('config payload includes hostnames and password entries when form gate active', function (): void {
    $site = accessGateTestSite();
    app(SiteAccessGateService::class)->addFormGatePassword($site, 'Sarah', 'gatepassword1');
    $site->refresh()->load(['accessGate', 'accessGatePasswords', 'domains']);

    $payload = app(SiteAccessGateService::class)->configPayload($site);

    expect($payload)->toMatchArray([
        'mode' => 'password',
        'site_id' => (string) $site->id,
        'hostnames' => ['gate.example.test'],
    ])
        ->and($payload['cookie_secret'])->not->toBeEmpty()
        ->and($payload['passwords'])->toHaveCount(1)
        ->and($payload['passwords'][0]['label'])->toBe('Sarah');
});

test('config payload returns null when method is form password but no enforceable passwords', function (): void {
    $site = accessGateTestSite();
    SiteAccessGate::query()->create([
        'site_id' => $site->id,
        'method' => SiteAccessGate::METHOD_FORM_PASSWORD,
        'cookie_secret' => str_repeat('s', 48),
    ]);

    expect(app(SiteAccessGateService::class)->configPayload($site->refresh()))->toBeNull();
});
