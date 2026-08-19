<?php

declare(strict_types=1);

namespace Tests\Feature\Databases;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshSession;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Site, 2: SiteBinding} */
function tunnelFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user, ['role' => 'admin']);
    $user->update(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'dply',
        'ssh_private_key' => 'k',
        'meta' => ['host_kind' => Server::HOST_KIND_VM],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

    $binding = SiteBinding::query()->create([
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

    return [$user, $site, $binding];
}

function tunnelInstallUrl(Site $site, SiteBinding $binding, int $port = 15432): string
{
    return URL::temporarySignedRoute('sites.databases.tunnel-install', now()->addMinutes(5), [
        'server' => $site->server_id,
        'site' => $site->id,
        'binding' => $binding->id,
        'port' => $port,
    ]);
}

test('the install script carries a key and an ssh config block', function (): void {
    [$user, $site, $binding] = tunnelFixture();

    $response = $this->actingAs($user)->get(tunnelInstallUrl($site, $binding));

    $response->assertOk();
    $body = (string) $response->getContent();

    expect($body)->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($body)->toContain('IdentitiesOnly yes')
        ->and($body)->toContain('Host dply-db-')
        // The whole point: the tunnel command needs no -i and no key path.
        ->and($body)->toContain('ssh -f -N -L 15432:db.example.ondigitalocean.com:25060 dply-db-');
});

test('the minted key can only forward to the one database', function (): void {
    [$user, $site, $binding] = tunnelFixture();

    $this->actingAs($user)->get(tunnelInstallUrl($site, $binding))->assertOk();

    $key = ServerAuthorizedKey::query()
        ->where('managed_key_type', ServerSshSession::class)
        ->latest('created_at')
        ->first();

    expect($key)->not->toBeNull()
        // restrict removes every capability, then exactly one forward is added
        // back — so this key can never yield a shell.
        ->and($key->key_options)->toContain('restrict')
        ->and($key->key_options)->toContain('permitopen="db.example.ondigitalocean.com:25060"')
        // The key itself stays bare so it remains fingerprintable.
        ->and($key->public_key)->toStartWith('ssh-ed25519 ');
});

test('the stored key is cleared on delivery and re-running rotates it', function (): void {
    [$user, $site, $binding] = tunnelFixture();

    $this->actingAs($user)->get(tunnelInstallUrl($site, $binding))->assertOk();

    $session = ServerSshSession::query()->latest('created_at')->first();
    expect($session->private_key)->toBeNull()
        ->and($session->delivered_at)->not->toBeNull();

    // Re-running rotates rather than accumulating: the previous key is revoked,
    // so a copy kept from an earlier run stops working.
    $this->actingAs($user)->get(tunnelInstallUrl($site, $binding))->assertOk();

    expect($session->fresh()->revoked_at)->not->toBeNull()
        ->and(ServerSshSession::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('a valid signature alone does not yield a key', function (): void {
    [, $site, $binding] = tunnelFixture();

    $this->get(tunnelInstallUrl($site, $binding))->assertRedirect();

    expect(ServerSshSession::query()->count())->toBe(0);
});

test('a user from another organization is refused', function (): void {
    [, $site, $binding] = tunnelFixture();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(tunnelInstallUrl($site, $binding))->assertForbidden();

    expect(ServerSshSession::query()->count())->toBe(0);
});
