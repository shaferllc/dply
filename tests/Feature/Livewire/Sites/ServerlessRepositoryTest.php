<?php

namespace Tests\Feature\Livewire\Sites\ServerlessRepositoryTest;

use App\Livewire\Sites\Repository;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function functionSite(?string $repoUrl = 'git@github.com:acme/api.git', string $branch = 'main'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'git_repository_url' => $repoUrl,
        'git_branch' => $branch,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'git_provider_kind' => 'github',
            'serverless' => [
                'runtime' => 'nodejs:20',
                'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/api',
                'proxy_slug' => 'acme-api',
            ],
        ],
    ]);

    return [$user, $server, $site];
}

test('repository page renders with default overview tab', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->assertOk()
        ->assertSee('Repository')
        ->assertSee('Overview')
        ->assertSee('Files')
        ->assertSee('Branches')
        ->assertSee('Connection')
        ->assertSet('tab', 'overview');
});

test('switch branch persists git branch', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->call('switchBranch', 'develop');

    expect($site->fresh()->git_branch)->toBe('develop');
});

test('switch branch rejects empty input', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->call('switchBranch', '');

    expect($site->fresh()->git_branch)->toBe('main');
});

test('switch repository updates url and resets branch', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->call('switchRepository', 'git@github.com:acme/web.git', 'production');

    $site->refresh();
    expect($site->git_repository_url)->toBe('git@github.com:acme/web.git');
    expect($site->git_branch)->toBe('production');
});

test('navigate to path and open file populate component state', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->call('navigateToPath', 'src/app')
        ->assertSet('filesPath', 'src/app')
        ->call('openFile', 'src/app/main.php')
        ->assertSet('filesOpenFile', 'src/app/main.php')
        ->call('closeFile')
        ->assertSet('filesOpenFile', '');
});

test('save connection writes branch and url and account id', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();
    $account = SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'acme-org',
        'nickname' => 'acme-org',
        'access_token' => 'token',
    ]);

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->set('connectionRepositoryUrl', 'git@github.com:acme/billing.git')
        ->set('connectionBranch', 'staging')
        ->set('connectionAccountId', (string) $account->id)
        ->call('saveConnection');

    $site->refresh();
    expect($site->git_repository_url)->toBe('git@github.com:acme/billing.git');
    expect($site->git_branch)->toBe('staging');
    expect((string) ($site->repositoryMeta()['git_source_control_account_id'] ?? ''))->toBe((string) $account->id);
    expect((string) ($site->repositoryMeta()['git_provider_kind'] ?? ''))->toBe('github');
});

test('branches tab calls provider api and lists branches', function () {
    [$user, $server, $site] = functionSite();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'acme-org',
        'nickname' => 'acme-org',
        'access_token' => 'gh-token',
    ]);

    Http::fake([
        'api.github.com/repos/acme/api' => Http::response(['default_branch' => 'main'], 200),
        'api.github.com/repos/acme/api/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'aaaaaaaaaaaaaaaaaaaa']],
            ['name' => 'develop', 'commit' => ['sha' => 'bbbbbbbbbbbbbbbbbbbb']],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->set('tab', 'branches')
        ->assertSee('main')
        ->assertSee('develop')
        ->assertSee('deploy branch');
});

test('files tab renders directory tree', function () {
    [$user, $server, $site] = functionSite();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'acme-org',
        'nickname' => 'acme-org',
        'access_token' => 'gh-token',
    ]);

    Http::fake([
        'api.github.com/repos/acme/api/contents/*' => Http::response([
            ['name' => 'src', 'path' => 'src', 'type' => 'dir', 'size' => 0, 'sha' => 'abc'],
            ['name' => 'README.md', 'path' => 'README.md', 'type' => 'file', 'size' => 256, 'sha' => 'def'],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $server, 'site' => $site])
        ->set('tab', 'files')
        ->assertSee('README.md')
        ->assertSee('src');
});

test('sites repository url resolves to new livewire page', function () {
    // Sanity: /servers/{server}/sites/{site}/repository must route to the
    // new dedicated Livewire page rather than fall through to the wildcard
    // sites.show section dispatcher. The path-based route is registered
    // before the wildcard so it wins; this guards against regressions in
    // route ordering.
    [$user, $server, $site] = functionSite();
    Http::fake();

    $response = $this->actingAs($user)->get(route('sites.repository', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk();
    $response->assertSee('Repository');
});
