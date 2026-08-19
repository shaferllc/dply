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
use App\Modules\Database\Services\TunnelAccessProvisioner;
use App\Support\Servers\DatabaseConnectionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * The installer route is signature-only by design: it is fetched with
 * `curl | bash`, which carries no session cookie. Authorization happens when the
 * key is MINTED, in the Connect modal; the signed URL is a short-lived, one-shot
 * capability for collecting an already-authorized key.
 */

/** @return array{0: User, 1: Server, 2: DatabaseConnectionTarget} */
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

    SiteBinding::query()->create([
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

    $target = new DatabaseConnectionTarget(
        engine: 'postgres',
        host: 'db.example.ondigitalocean.com',
        port: 25060,
        database: 'defaultdb',
        username: 'doadmin',
        sslMode: 'require',
        label: 'primary',
    );

    return [$user, $server, $target];
}

function installUrl(ServerSshSession $session): string
{
    return URL::temporarySignedRoute('database-tunnels.install', now()->addMinutes(10), [
        'session' => $session->id,
        'host' => 'db.example.ondigitalocean.com',
        'dbport' => 25060,
        'port' => 15432,
    ]);
}

test('the install script carries a key and an ssh config block', function (): void {
    [$user, $server, $target] = tunnelFixture();
    $session = app(TunnelAccessProvisioner::class)->provision($server, $user, $target);

    $body = (string) $this->get(installUrl($session))->assertOk()->getContent();

    expect($body)->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($body)->toContain('IdentitiesOnly yes')
        ->and($body)->toContain('Host dply-db-')
        // The whole point: the tunnel command needs no -i and no key path.
        ->and($body)->toContain('ssh -f -N -L 15432:db.example.ondigitalocean.com:25060 dply-db-');
});

test('the minted key can only forward to the one database', function (): void {
    [$user, $server, $target] = tunnelFixture();
    app(TunnelAccessProvisioner::class)->provision($server, $user, $target);

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

test('the key is delivered once and a replayed link yields nothing', function (): void {
    [$user, $server, $target] = tunnelFixture();
    $session = app(TunnelAccessProvisioner::class)->provision($server, $user, $target);
    $url = installUrl($session);

    $this->get($url)->assertOk();

    expect($session->fresh()->private_key)->toBeNull()
        ->and($session->fresh()->delivered_at)->not->toBeNull();

    // A kept URL is inert once the key has been collected.
    $this->get($url)->assertStatus(410);
});

test('re-provisioning rotates the key and revokes the previous one', function (): void {
    [$user, $server, $target] = tunnelFixture();
    $provisioner = app(TunnelAccessProvisioner::class);

    $first = $provisioner->provision($server, $user, $target);
    $second = $provisioner->provision($server, $user, $target);

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->revoked_at)->not->toBeNull()
        ->and(ServerSshSession::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('an expired session yields nothing', function (): void {
    [$user, $server, $target] = tunnelFixture();
    $session = app(TunnelAccessProvisioner::class)->provision($server, $user, $target);
    $session->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->get(installUrl($session))->assertStatus(410);
});

test('an unsigned request is rejected', function (): void {
    [$user, $server, $target] = tunnelFixture();
    $session = app(TunnelAccessProvisioner::class)->provision($server, $user, $target);

    $this->get(route('database-tunnels.install', ['session' => $session->id]))->assertForbidden();
});

test('an unknown session is not found', function (): void {
    tunnelFixture();

    $url = URL::temporarySignedRoute('database-tunnels.install', now()->addMinutes(10), [
        'session' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
    ]);

    $this->get($url)->assertNotFound();
});
