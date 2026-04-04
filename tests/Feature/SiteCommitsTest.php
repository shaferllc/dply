<?php

namespace Tests\Feature;

use App\Livewire\Sites\Commits as SitesCommits;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SiteCommitsTest extends TestCase
{
    use RefreshDatabase;

    protected function actingOrgOwner(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_commits_page_loads_and_shows_provider_error_without_oauth(): void
    {
        $user = $this->actingOrgOwner();
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
    }

    public function test_commits_page_renders_github_commits(): void
    {
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

        $user = $this->actingOrgOwner();
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
    }

    public function test_commits_route_ok(): void
    {
        $user = $this->actingOrgOwner();
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
    }
}
