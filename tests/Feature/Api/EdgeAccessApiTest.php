<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('edge access api returns current preview protection settings', function () {
    [$headers, $site] = edgeAccessApiContext(['edge.read']);

    EdgeSiteAccessRule::query()->create([
        'site_id' => $site->id,
        'mode' => EdgeSiteAccessRule::MODE_PASSWORD,
        'cookie_secret' => 'secret',
        'password_salt' => 'salt',
        'password_verifier' => hash('sha256', 'saltgate-me'),
    ]);

    $this->getJson('/api/v1/edge/sites/'.$site->id.'/access', $headers)
        ->assertOk()
        ->assertJsonPath('data.mode', 'password')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.password_set', true);
});

test('edge access api updates password protection and republishes host map', function () {
    config(['edge.fake.enabled' => true]);

    [$headers, $site] = edgeAccessApiContext(['edge.write']);

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/site/deploy/',
        'git_branch' => 'main',
        'published_at' => now(),
        'aliases' => ['alias.example.test'],
    ]);
    $site->mergeEdgeMeta(['active_deployment_id' => (string) $deployment->id]);
    $site->save();

    $this->patchJson('/api/v1/edge/sites/'.$site->id.'/access', [
        'mode' => 'password',
        'password' => 'review-only',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.mode', 'password')
        ->assertJsonPath('data.password_set', true);

    $map = Cache::get('edge:fake:host-map', []);
    $alias = $map['alias.example.test'] ?? null;
    expect($alias['access_gate']['mode'] ?? null)->toBe('password');
});

test('edge access api rejects updates on preview child sites', function () {
    [$headers, $parent] = edgeAccessApiContext(['edge.write']);

    $preview = Site::factory()->create([
        'organization_id' => $parent->organization_id,
        'server_id' => $parent->server_id,
        'edge_backend' => $parent->edge_backend,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => ['preview_parent_site_id' => (string) $parent->id],
        ],
    ]);

    $this->patchJson('/api/v1/edge/sites/'.$preview->id.'/access', [
        'mode' => 'password',
        'password' => 'nope',
    ], $headers)
        ->assertStatus(422);
});

/**
 * @param  list<string>  $abilities
 * @return array{0: array<string, string>, 1: Site}
 */
function edgeAccessApiContext(array $abilities): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'edge-access-test', null, $abilities);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    return [
        [
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ],
        $site,
    ];
}
