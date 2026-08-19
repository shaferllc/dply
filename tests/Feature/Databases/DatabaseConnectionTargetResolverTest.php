<?php

declare(strict_types=1);

namespace Tests\Feature\Databases;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolverSite(array $serverOverrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->create(array_merge([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'fake-key',
        'meta' => ['host_kind' => Server::HOST_KIND_VM],
    ], $serverOverrides));

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
}

function cloudBinding(Site $site, CloudDatabase $database): SiteBinding
{
    return SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'managed',
        'status' => 'active',
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $database->id,
        'config' => ['placement' => 'managed'],
        'injected_env' => [],
    ]);
}

test('a managed cluster resolves to a target without exposing the password', function (): void {
    $site = resolverSite();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $site->organization_id,
    ]);

    $target = app(DatabaseConnectionTargetResolver::class)->forBinding(cloudBinding($site, $database));

    expect($target)->not->toBeNull()
        ->and($target->host)->toBe('db.example.ondigitalocean.com')
        ->and($target->port)->toBe(25060)
        ->and($target->username)->toBe('doadmin')
        ->and($target->database)->toBe('defaultdb')
        ->and($target->supportsTrustedSourceWrites)->toBeTrue()
        // The DTO has no password property at all — the secret cannot leak
        // through view data by construction.
        ->and(property_exists($target, 'password'))->toBeFalse();
});

test('a still-provisioning cluster resolves to nothing', function (): void {
    $site = resolverSite();

    // connection stays empty until the provider reports a host, so there is
    // genuinely nothing to connect to yet.
    $database = CloudDatabase::factory()->create([
        'organization_id' => $site->organization_id,
        'status' => CloudDatabase::STATUS_PROVISIONING,
    ]);

    expect(app(DatabaseConnectionTargetResolver::class)->forBinding(cloudBinding($site, $database)))->toBeNull();
});

test('an operator-typed external host resolves from the binding envelope', function (): void {
    $site = resolverSite();

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'external',
        'status' => 'active',
        'name' => 'legacy',
        'target_type' => null,
        'target_id' => null,
        'config' => ['placement' => 'external'],
        'injected_env' => [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.internal.acme.com',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'acme',
            'DB_USERNAME' => 'acme_user',
            'DB_PASSWORD' => 'super-secret',
        ],
    ]);

    $target = app(DatabaseConnectionTargetResolver::class)->forBinding($binding);

    expect($target)->not->toBeNull()
        ->and($target->host)->toBe('db.internal.acme.com')
        ->and($target->engine)->toBe('postgres')
        ->and($target->kind)->toBe(DatabaseConnectionTarget::KIND_EXTERNAL)
        // dply did not provision it, so its firewall is not ours to edit.
        ->and($target->supportsTrustedSourceWrites)->toBeFalse()
        ->and($target->uri())->not->toContain('super-secret');
});

test('an on-box binding is not treated as remote', function (): void {
    $site = resolverSite();

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'managed',
        'status' => 'active',
        'name' => 'local',
        'config' => ['placement' => 'on_box'],
        'injected_env' => ['DB_HOST' => '127.0.0.1'],
    ]);

    expect(app(DatabaseConnectionTargetResolver::class)->forBinding($binding))->toBeNull();
});

test('a synthetic serverless host cannot be a jump host', function (): void {
    // sites.server_id is NOT NULL, so the failure mode is not a missing server —
    // it is a server row that does not accept SSH at all.
    $site = resolverSite(['meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS]]);
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $site->organization_id]);

    $resolver = app(DatabaseConnectionTargetResolver::class);
    $target = $resolver->forBinding(cloudBinding($site, $database));

    expect($resolver->tunnelUnavailableReason($target, $site->server))
        ->toBe(DatabaseConnectionTargetResolver::REASON_SERVER_NOT_SSHABLE);
});

test('an unprovisioned vm cannot be a jump host', function (): void {
    $site = resolverSite(['status' => 'provisioning']);
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $site->organization_id]);

    $resolver = app(DatabaseConnectionTargetResolver::class);
    $target = $resolver->forBinding(cloudBinding($site, $database));

    expect($resolver->tunnelUnavailableReason($target, $site->server))
        ->toBe(DatabaseConnectionTargetResolver::REASON_SERVER_NOT_READY);
});

test('a ready vm yields a usable tunnel', function (): void {
    $site = resolverSite();
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $site->organization_id]);

    $resolver = app(DatabaseConnectionTargetResolver::class);
    $target = $resolver->forBinding(cloudBinding($site, $database));

    expect($resolver->tunnelUnavailableReason($target, $site->server))->toBeNull();
});

test('a publicly reachable vendor needs no tunnel', function (): void {
    $site = resolverSite();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $site->organization_id,
        'backend' => CloudDatabase::BACKEND_NEON,
    ]);

    $resolver = app(DatabaseConnectionTargetResolver::class);
    $target = $resolver->forBinding(cloudBinding($site, $database));

    expect($resolver->tunnelUnavailableReason($target, $site->server))
        ->toBe(DatabaseConnectionTargetResolver::REASON_PROVIDER_PUBLIC);
});
