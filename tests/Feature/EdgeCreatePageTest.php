<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCreatePageTest;

use App\Enums\SiteType;
use App\Livewire\Edge\Create;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use ReflectionMethod;

uses(RefreshDatabase::class);

usesFeatures('surface.edge', 'surface.cloud');

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

test('ssr detection auto selects hybrid and name from repo when no cloud app', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->tap(function ($component): void {
            $method = new ReflectionMethod($component->instance(), 'applyDetectedRuntimePrefills');
            $method->setAccessible(true);
            $method->invoke($component->instance());
        })
        ->assertSet('form.runtime_mode', 'hybrid')
        ->assertSet('form.name', 'Next App')
        ->assertSet('form.origin_url', '');
});

test('ssr detection auto fills origin from matching cloud app repo', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Next API',
        'container_backend' => 'digitalocean_app_platform',
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'live_url' => 'https://next-api.ondigitalocean.app',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'hybrid')
        ->assertSet('form.origin_url', 'https://next-api.ondigitalocean.app');
});

test('rejects ssr-looking detection on deploy when hybrid origin missing', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'static')
        ->set('runtimeModeTouched', true)
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->call('deploy')
        ->assertNoRedirect();

    expect(Site::query()->count())->toBe(0);
});

test('hybrid mode leaves origin empty when no cloud app matches', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.runtime_mode', 'hybrid')
        ->set('form.name', 'My App')
        ->assertSet('form.origin_url', '')
        ->set('form.name', 'SSR App')
        ->assertSet('form.origin_url', '');
});

test('hybrid mode uses live cloud app url when one is linked manually', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $cloudSite = Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Next API',
        'container_backend' => 'digitalocean_app_platform',
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'live_url' => 'https://next-api.ondigitalocean.app',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('form.runtime_mode', 'hybrid')
        ->set('form.origin_cloud_site_id', (string) $cloudSite->id)
        ->assertSet('form.origin_url', 'https://next-api.ondigitalocean.app');
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
        ->assertSet('form.output_dir', '_site');
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

test('ssr without origin shows auto provision messaging when cloud available', function () {
    config(['server_provision_fake.env_flag' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'hybrid')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->assertSee('Deploy hybrid stack')
        ->assertSee('provisioned from this repository')
        ->assertSee('SSR origin');
});

test('deploy auto provisions hybrid stack when ssr detected and cloud available', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'hybrid')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->call('deploy')
        ->assertRedirect();

    expect(Site::query()->where('type', SiteType::Container)->count())->toBe(1);
});

test('deploy hybrid stack redirects to cloud workspace', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->call('deployHybridStack')
        ->assertRedirect();

    $cloudSite = Site::query()->where('type', SiteType::Container)->first();
    expect($cloudSite)->not->toBeNull();
    expect($cloudSite->meta['container']['hybrid_edge_stack']['status'] ?? null)->toBe('awaiting_origin');
});

test('hybrid stack auto provision hidden when origin auto filled', function () {
    config(['server_provision_fake.env_flag' => true]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Next API',
        'container_backend' => 'digitalocean_app_platform',
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'live_url' => 'https://next-api.ondigitalocean.app',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
        ])
        ->set('form.runtime_mode', 'hybrid')
        ->assertSet('form.origin_url', 'https://next-api.ondigitalocean.app')
        ->assertSee('SSR origin URL')
        ->assertSee('Deploy edge app');
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
