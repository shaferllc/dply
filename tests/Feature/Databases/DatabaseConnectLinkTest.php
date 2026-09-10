<?php

declare(strict_types=1);

namespace Tests\Feature\Databases;

use App\Models\CloudDatabase;
use App\Models\CloudDatabaseTrustedSource;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Site, 2: SiteBinding} */
function connectLinkFixture(): array
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

function connectLinkUrl(Site $site, SiteBinding $binding, string $via = 'direct', int $port = 25060): string
{
    return URL::temporarySignedRoute('sites.databases.connect-link', now()->addMinutes(2), [
        'server' => $site->server_id,
        'site' => $site->id,
        'binding' => $binding->id,
        'via' => $via,
        'port' => $port,
    ]);
}

test('an authorized operator gets a hand-off carrying the credential', function (): void {
    [$user, $site, $binding] = connectLinkFixture();

    $response = $this->actingAs($user)->get(connectLinkUrl($site, $binding));

    $response->assertOk()
        ->assertSee('secret-pass', escape: false)
        ->assertSee('db.example.ondigitalocean.com', escape: false)
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // A body containing a live credential must never be cached or referred on.
    // Symfony re-orders Cache-Control directives, so assert on presence.
    $cacheControl = $response->headers->get('Cache-Control') ?? '';
    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private');
});

test('a site database link hands off its stored password on the tunnel port', function (): void {
    [$user, $site] = connectLinkFixture();
    $db = ServerDatabase::query()->create([
        'server_id' => $site->server_id,
        'site_id' => $site->id,
        'name' => 'dply_blog',
        'engine' => 'mariadb',
        'username' => 'dply_blog',
        'password' => 'wp-secret',
        'host' => 'localhost',
    ]);
    $url = URL::temporarySignedRoute('sites.databases.server-connect-link', now()->addMinutes(2), [
        'server' => $site->server_id,
        'site' => $site->id,
        'database' => $db->id,
    ]);

    $this->actingAs($user)->get($url)
        ->assertOk()
        ->assertSee('wp-secret', escape: false)
        ->assertSee('127.0.0.1:15432', escape: false)
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // dply no longer holds the password (e.g. adopted) → nothing to hand off.
    $db->update(['credentials_known' => false]);
    $this->actingAs($user)->get($url)->assertNotFound();
});

test('the site database uri endpoint hands curl the credentialed tunnel uri', function (): void {
    [, $site] = connectLinkFixture();
    $db = ServerDatabase::query()->create([
        'server_id' => $site->server_id,
        'site_id' => $site->id,
        'name' => 'dply_blog',
        'engine' => 'mariadb',
        'username' => 'dply_blog',
        'password' => 'wp-secret',
        'host' => 'localhost',
    ]);
    $url = URL::temporarySignedRoute('database-connections.server-uri', now()->addMinutes(2), [
        'database' => $db->id,
        'port' => 15432,
    ]);

    // curl carries no session — the signature alone authorizes it.
    $this->get($url)
        ->assertOk()
        ->assertSee('mysql://dply_blog:wp-secret@127.0.0.1:15432/dply_blog', escape: false);

    $this->get(route('database-connections.server-uri', ['database' => $db->id]))->assertForbidden();

    $db->update(['credentials_known' => false]);
    $this->get($url)->assertNotFound();
});

test('the tunnel variant points at the forwarded local port', function (): void {
    [$user, $site, $binding] = connectLinkFixture();

    $this->actingAs($user)
        ->get(connectLinkUrl($site, $binding, 'tunnel', 15432))
        ->assertOk()
        ->assertSee('127.0.0.1:15432', escape: false)
        ->assertDontSee('db.example.ondigitalocean.com:25060', escape: false);
});

test('a valid signature alone does not reach the credential', function (): void {
    [, $site, $binding] = connectLinkFixture();

    // The whole point of pairing signature with session: a leaked link is inert.
    $this->get(connectLinkUrl($site, $binding))->assertRedirect();
});

test('an unsigned request is rejected', function (): void {
    [$user, $site, $binding] = connectLinkFixture();

    $this->actingAs($user)
        ->get(route('sites.databases.connect-link', [
            'server' => $site->server_id,
            'site' => $site->id,
            'binding' => $binding->id,
        ]))
        ->assertForbidden();
});

test('a user from another organization cannot open the link', function (): void {
    [, $site, $binding] = connectLinkFixture();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(connectLinkUrl($site, $binding))
        ->assertForbidden();
});

test('a binding from another site is not found', function (): void {
    [$user, $site] = connectLinkFixture();
    [, , $otherBinding] = connectLinkFixture();

    $this->actingAs($user)
        ->get(connectLinkUrl($site, $otherBinding))
        ->assertNotFound();
});

test('the signed link can grant access before handing off', function (): void {
    [$user, $site, $binding] = connectLinkFixture();

    Http::fake([
        '*/databases/*/firewall' => Http::sequence()
            ->pushResponse(Http::response(['rules' => [
                ['type' => 'droplet', 'value' => '145132'],
            ]], 200))
            ->whenEmpty(Http::response([], 204)),
    ]);

    $url = URL::temporarySignedRoute('sites.databases.connect-link', now()->addMinutes(2), [
        'server' => $site->server_id,
        'site' => $site->id,
        'binding' => $binding->id,
        'via' => 'direct',
        'allow' => '1',
        'ip' => '203.0.113.7',
    ]);

    $this->actingAs($user)->get($url)->assertOk();

    expect(CloudDatabaseTrustedSource::query()
        ->where('ip_address', '203.0.113.7')
        ->exists())->toBeTrue();
});

test('a non-admin cannot grant access through the signed link', function (): void {
    [, $site, $binding] = connectLinkFixture();

    $deployer = User::factory()->create();
    $site->organization->users()->attach($deployer, ['role' => 'deployer']);
    $deployer->update(['current_organization_id' => $site->organization_id]);

    $url = URL::temporarySignedRoute('sites.databases.connect-link', now()->addMinutes(2), [
        'server' => $site->server_id,
        'site' => $site->id,
        'binding' => $binding->id,
        'via' => 'direct',
        'allow' => '1',
        'ip' => '203.0.113.7',
    ]);

    $this->actingAs($deployer)->get($url);

    // The hand-off may still render, but the network exposure must not change.
    expect(CloudDatabaseTrustedSource::query()->count())->toBe(0);
});
