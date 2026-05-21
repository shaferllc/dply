<?php

namespace Tests\Feature\Livewire\Sites;

use App\Livewire\Sites\Repository;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ServerlessRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function functionSite(?string $repoUrl = 'git@github.com:acme/api.git', string $branch = 'main'): array
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

    public function test_repository_page_renders_with_default_overview_tab(): void
    {
        [$user, $server, $site] = $this->functionSite();
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
    }

    public function test_switch_branch_persists_git_branch(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Repository::class, ['server' => $server, 'site' => $site])
            ->call('switchBranch', 'develop');

        $this->assertSame('develop', $site->fresh()->git_branch);
    }

    public function test_switch_branch_rejects_empty_input(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Repository::class, ['server' => $server, 'site' => $site])
            ->call('switchBranch', '');

        $this->assertSame('main', $site->fresh()->git_branch);
    }

    public function test_switch_repository_updates_url_and_resets_branch(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Repository::class, ['server' => $server, 'site' => $site])
            ->call('switchRepository', 'git@github.com:acme/web.git', 'production');

        $site->refresh();
        $this->assertSame('git@github.com:acme/web.git', $site->git_repository_url);
        $this->assertSame('production', $site->git_branch);
    }

    public function test_navigate_to_path_and_open_file_populate_component_state(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Repository::class, ['server' => $server, 'site' => $site])
            ->call('navigateToPath', 'src/app')
            ->assertSet('filesPath', 'src/app')
            ->call('openFile', 'src/app/main.php')
            ->assertSet('filesOpenFile', 'src/app/main.php')
            ->call('closeFile')
            ->assertSet('filesOpenFile', '');
    }

    public function test_save_connection_writes_branch_and_url_and_account_id(): void
    {
        [$user, $server, $site] = $this->functionSite();
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
        $this->assertSame('git@github.com:acme/billing.git', $site->git_repository_url);
        $this->assertSame('staging', $site->git_branch);
        $this->assertSame((string) $account->id, (string) ($site->meta['git_source_control_account_id'] ?? ''));
        $this->assertSame('github', (string) ($site->meta['git_provider_kind'] ?? ''));
    }

    public function test_branches_tab_calls_provider_api_and_lists_branches(): void
    {
        [$user, $server, $site] = $this->functionSite();
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
    }

    public function test_files_tab_renders_directory_tree(): void
    {
        [$user, $server, $site] = $this->functionSite();
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
    }

    public function test_serverless_section_repository_redirects_to_new_route(): void
    {
        [$user, $server, $site] = $this->functionSite();

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
            'section' => 'repository',
        ]));

        $response->assertRedirect(route('sites.repository', [
            'server' => $server,
            'site' => $site,
        ]));
    }
}
