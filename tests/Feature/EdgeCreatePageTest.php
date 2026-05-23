<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCreatePageTest;

use App\Livewire\Edge\Create;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('surface.edge');

test('guest is redirected from edge create', function () {
    $this->get(route('edge.create'))
        ->assertRedirect(route('login'));
});

test('authenticated user can load edge create form', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertOk()
        ->assertSee('Deploy an edge app')
        ->assertSee('Git repository')
        ->assertSee('Build command override')
        ->assertSee('SPA fallback')
        ->assertSee('Deploy on push')
        ->assertSee('Dply Edge (managed)')
        ->assertSee('Your Cloudflare account');
});

test('returns 404 when surface edge inactive', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertStatus(400);
});

test('rejects ssr-looking detection on deploy', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->call('deploy')
        ->assertNoRedirect();

    expect(Site::query()->count())->toBe(0);
});

test('shows manual entry when no git accounts linked', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->assertSet('linkedSourceControlAccounts', [])
        ->assertSee('owner/repo or a full GitHub URL')
        ->assertDontSee('Pick from connected account');
});

test('renders repo picker when git accounts linked', function () {
    $user = ownerWithOrg();

    $browser = new class extends SourceControlRepositoryBrowser
    {
        public function __construct() {}

        public function accountsForUser($user): array
        {
            return [['id' => 'acct-1', 'provider' => 'github', 'label' => 'Github - acme']];
        }

        public function repositoriesForAccount($account): array
        {
            return [
                ['url' => 'https://github.com/acme/web', 'label' => 'acme/web', 'branch' => 'main'],
            ];
        }
    };
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->assertSee('Pick from connected account')
        ->assertSee('Enter manually')
        ->assertSee('Github - acme');
});

test('picker selection populates repo and branch', function () {
    $user = ownerWithOrg();

    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'github:acme',
        'nickname' => 'acme',
        'access_token' => encrypt('t'),
    ]);

    $browser = new class($account->id) extends SourceControlRepositoryBrowser
    {
        public function __construct(public string $accountId) {}

        public function accountsForUser($user): array
        {
            return [['id' => $this->accountId, 'provider' => 'github', 'label' => 'Github - acme']];
        }

        public function repositoriesForAccount($account): array
        {
            return [
                ['url' => 'https://github.com/acme/marketing.git', 'label' => 'acme/marketing', 'branch' => 'develop'],
            ];
        }
    };
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repository_selection', 'https://github.com/acme/marketing.git')
        ->assertSet('repo', 'acme/marketing')
        ->assertSet('branch', 'develop');
});

test('auto detects when a complete manual repo is entered', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo_source', 'manual')
        ->set('repo', '11ty/eleventy-base-blog')
        ->assertSet('detectedPlan.framework', 'eleventy')
        ->assertSet('output_dir', '_site');
});

test('does not auto detect for incomplete manual repo slug', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo_source', 'manual')
        ->set('repo', '11ty')
        ->set('branch', 'main')
        ->assertSet('detectedPlan', []);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
