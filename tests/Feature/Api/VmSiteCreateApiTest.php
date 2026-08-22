<?php

namespace Tests\Feature\Api\VmSiteCreateApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User, 2: Server, 3: string}
 */
function vmSetup(array $abilities = ['sites.create', 'account.read'], array $serverMeta = ['webserver' => 'nginx']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'meta' => $serverMeta,
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$organization, $user, $server, $plaintext];
}

beforeEach(function () {
    Bus::fake();
    Feature::define('surface.vm_cli_create', true);
});

it('refuses when CLI creation of server sites is off', function () {
    Feature::define('surface.vm_cli_create', false);
    [, , $server, $token] = vmSetup();

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", ['name' => 'shop', 'dry_run' => true])
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'cli_create_disabled');
});

it('refuses a token without sites.create', function () {
    [, , $server, $token] = vmSetup(['sites.read']);

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", ['name' => 'shop'])
        ->assertStatus(403);
});

it('will not create on a server belonging to another organization', function () {
    [, , , $token] = vmSetup();
    $other = Server::factory()->create(['status' => Server::STATUS_READY, 'meta' => ['webserver' => 'nginx']]);

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$other->id}/sites", ['name' => 'shop', 'dry_run' => true])
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'source_required');
});

it('will not create on a server that is not ready yet', function () {
    [, , $server, $token] = vmSetup();
    $server->forceFill(['status' => Server::STATUS_PENDING])->save();

    // A site created against a half-built server sits pending forever, and the
    // reason lives on the server rather than the site.
    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", ['name' => 'shop', 'dry_run' => true])
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'server_not_ready');
});

it('refuses a host whose sites need options only the dashboard collects', function () {
    // Headless host: no webserver, so no document root and no domain.
    [, , $server, $token] = vmSetup(serverMeta: ['webserver' => 'none']);

    $response = $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", ['name' => 'shop', 'dry_run' => true])
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'host_unsupported');

    expect($response->json('blocker.resolve_url'))->toContain('/sites/create');
});

it('previews the document root without creating anything', function () {
    [, , $server, $token] = vmSetup();

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", [
            'name' => 'Shop Front',
            'type' => 'php',
            'dry_run' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.plan.type', 'php')
        // PHP is served from public/, and the path follows the same convention
        // as Site::conventionalRepositoryPath().
        ->assertJsonPath('data.plan.document_root', '/home/dply/shop-front/public');

    expect(Site::query()->count())->toBe(0);
});

it('keys the document root on the hostname when one is given', function () {
    [, , $server, $token] = vmSetup();

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", [
            'name' => 'Shop Front',
            'type' => 'static',
            'primary_hostname' => 'Shop.Example.COM',
            'dry_run' => true,
        ])
        ->assertOk()
        // Static sites serve from the release root, not public/.
        ->assertJsonPath('data.plan.document_root', '/home/dply/shop.example.com');
});

it('creates the site, its domain, and queues provisioning', function () {
    [, , $server, $token] = vmSetup();

    $response = $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", [
            'name' => 'shop',
            'type' => 'php',
            'primary_hostname' => 'shop.example.com',
            'git_repository_url' => 'https://github.com/acme/shop.git',
            'git_branch' => 'main',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.kind', 'vm');

    $site = Site::query()->find($response->json('data.id'));

    expect($site)->not->toBeNull()
        ->and($site->server_id)->toBe($server->id)
        ->and($site->status)->toBe(Site::STATUS_PENDING)
        ->and($site->git_repository_url)->toBe('https://github.com/acme/shop.git')
        ->and($site->domains()->where('hostname', 'shop.example.com')->exists())->toBeTrue();

    Bus::assertDispatched(\App\Jobs\ProvisionSiteJob::class);
});

/**
 * The replay has to be checked *before* the quota gate: the site the first call
 * created consumes quota, so a gate-first retry would answer "quota exceeded"
 * instead of handing back the site it already made.
 */
it('replays a repeated idempotency key rather than creating twice', function () {
    [, , $server, $token] = vmSetup();

    $payload = ['name' => 'shop', 'type' => 'static'];

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'vm-1')
        ->postJson("/api/v1/servers/{$server->id}/sites", $payload)
        ->assertStatus(201);

    $this->withToken($token)->withHeader('Idempotency-Key', 'vm-1')
        ->postJson("/api/v1/servers/{$server->id}/sites", $payload)
        ->assertStatus(200)
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Site::query()->count())->toBe(1);
});

it('stores an imported .env encrypted, and never echoes it back', function () {
    [, , $server, $token] = vmSetup();

    $response = $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/sites", [
            'name' => 'shop',
            'type' => 'php',
            'env_file_content' => "APP_KEY=base64:hunter2\nDB_PASSWORD=swordfish\n",
        ])
        ->assertStatus(201);

    $site = Site::query()->find($response->json('data.id'));

    expect($site->env_file_content)->toContain('swordfish');
    expect((string) \DB::table('sites')->where('id', $site->id)->value('env_file_content'))
        ->not->toContain('swordfish');
    expect(json_encode($response->json()))->not->toContain('swordfish');
});

it('reports vm as CLI-creatable and server-scoped in capabilities', function () {
    [, , , $token] = vmSetup(['account.read']);

    $this->withToken($token)
        ->getJson('/api/v1/capabilities')
        ->assertOk()
        ->assertJsonPath('data.kinds.vm.cli_create', true)
        ->assertJsonPath('data.kinds.vm.requires_server', true);
});
