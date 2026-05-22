<?php

namespace Tests\Feature\SiteCommitsTest;

use App\Livewire\Sites\Commits as SitesCommits;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingOrgOwner(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('commits page loads and shows provider error without oauth', function () {
    $user = actingOrgOwner();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo',
        'git_branch' => 'main',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    Livewire::actingAs($user)
        ->test(SitesCommits::class, ['server' => $server, 'site' => $site])
        ->assertSee('Link a GitHub account', false);
});

test('commits page renders github commits', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            [
                'sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
                'html_url' => 'https://github.com/acme/demo/commit/deadbeef',
                'commit' => [
                    'message' => "Fix billing edge case\n\nDetails",
                    'author' => [
                        'name' => 'Taylor Otwell',
                        'email' => 'taylor@example.com',
                        'date' => '2024-01-15T12:00:00Z',
                    ],
                ],
            ],
        ], 200),
    ]);

    $user = actingOrgOwner();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo',
        'git_branch' => 'main',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-test-1',
        'access_token' => 'test-token',
    ]);

    Livewire::actingAs($user)
        ->test(SitesCommits::class, ['server' => $server, 'site' => $site])
        ->assertSee('Fix billing edge case')
        ->assertSee('Taylor Otwell')
        ->assertSee('deadbeef')
        ->assertSee('View commit');
});

test('commits route ok', function () {
    $user = actingOrgOwner();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $this->actingAs($user)
        ->get(route('sites.commits', [$server, $site]))
        ->assertOk()
        ->assertSee('Commits');
});
