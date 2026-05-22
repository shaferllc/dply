<?php

declare(strict_types=1);

namespace Tests\Feature\CloudCreatePageTest;

use App\Jobs\ProvisionCloudSiteJob;
use App\Livewire\Cloud\Create as CloudCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\GitCloner;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePreview;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

test('page renders with no backends connected warning', function () {
    config(['server_provision_fake.env_flag' => false]);
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('cloud.create'));

    $response->assertOk()
        ->assertSee('Deploy a container app')
        ->assertSee('No container backend connected')
        ->assertSee('Connect DigitalOcean')
        ->assertSee('Connect AWS App Runner');
});
test('page shows fake cloud notice instead of warning when no creds and fake on', function () {
    config(['server_provision_fake.env_flag' => true]);
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('cloud.create'));

    $response->assertOk()
        ->assertSee('Fake-cloud mode is on')
        ->assertDontSee('No container backend connected');
});
test('page hides warning when backend connected', function () {
    $user = ownerWithOrg();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $response = $this->actingAs($user)->get(route('cloud.create'));

    $response->assertOk()
        ->assertDontSee('No container backend connected');
});
test('changing backend resets region to first available', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('backend', 'aws_app_runner')
        ->assertSet('region', 'us-east-1')
        ->set('backend', 'digitalocean_app_platform')
        ->assertSet('region', 'ams');
});
test('deploy dispatches provision job and redirects', function () {
    Queue::fake();
    $user = ownerWithOrg();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('name', 'Acme API')
        ->set('image', 'ghcr.io/acme/api:v1')
        ->set('port', 8080)
        ->set('region', 'nyc')
        ->set('backend', 'digitalocean_app_platform')
        ->call('deploy')
        ->assertHasNoErrors();

    Queue::assertPushed(ProvisionCloudSiteJob::class);
});
test('deploy with no credential shows toast error', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('name', 'Lonely')
        ->set('image', 'nginx:1')
        ->set('region', 'nyc')
        ->set('backend', 'auto')
        ->call('deploy')
        ->assertDispatched('notify');
});
test('deploy validates required fields', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->call('deploy')
        ->assertHasErrors(['name', 'image']);
});
test('source tab renders repo inputs', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->assertSee('GitHub repo')
        ->assertSee('Branch')
        ->assertSee('Auto-deploy on push to this branch')
        ->assertSee('owner/name or full GitHub URL');
});
test('source mode validates repo and branch', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('name', 'svc')
        ->call('deploy')
        ->assertHasErrors(['repo']);
});
test('source mode warns when aws lacks github connection', function () {
    $user = ownerWithOrg();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's'],
    ]);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('backend', 'aws_app_runner')
        ->assertSee('AWS App Runner needs a GitHub connection');
});
test('source mode skips warning when aws has github connection', function () {
    $user = ownerWithOrg();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => [
            'access_key_id' => 'k',
            'secret_access_key' => 's',
            'github_connection_arn' => 'arn:aws:apprunner:us-east-1:1234:connection/dply/xyz',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('backend', 'aws_app_runner')
        ->assertDontSee('AWS App Runner needs a GitHub connection');
});
test('source tab shows only manual entry when no accounts linked', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->assertSet('linkedSourceControlAccounts', [])
        ->assertSee('owner/name or full GitHub URL')
        ->assertDontSee('Pick from connected account');
});
test('source tab renders picker when accounts linked', function () {
    $user = ownerWithOrg();

    $browser = new class extends SourceControlRepositoryBrowser
    {
        public function __construct() {}

        public function accountsForUser($user): array
        {
            return [['id' => 'acct-1', 'label' => 'github:acme', 'name' => 'acme']];
        }

        public function repositoriesForAccount($account): array
        {
            return [
                ['url' => 'https://github.com/acme/api', 'name' => 'acme/api', 'branch' => 'main'],
                ['url' => 'https://github.com/acme/web', 'name' => 'acme/web', 'branch' => 'develop'],
            ];
        }
    };
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->assertSee('Pick from connected account')
        ->assertSee('Enter manually')
        ->assertSee('github:acme');
});
test('picker selection populates repo and branch', function () {
    $user = ownerWithOrg();

    // Seed a real SocialAccount because the component's
    // loadRepositoriesForSelectedAccount() asks the User's relation
    // for it. The browser fake is consulted only after that lookup.
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
            return [['id' => $this->accountId, 'label' => 'github:acme', 'name' => 'acme']];
        }

        public function repositoriesForAccount($account): array
        {
            return [
                ['url' => 'https://github.com/acme/api.git', 'name' => 'acme/api', 'branch' => 'develop'],
            ];
        }
    };
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('repository_selection', 'https://github.com/acme/api.git')
        ->assertSet('repo', 'acme/api')
        ->assertSet('branch', 'develop');
});
test('source mode dispatches provision with source meta', function () {
    Queue::fake();
    $user = ownerWithOrg();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('name', 'Acme API')
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->set('port', 8080)
        ->set('region', 'nyc')
        ->set('backend', 'digitalocean_app_platform')
        ->call('deploy')
        ->assertHasNoErrors();

    Queue::assertPushed(ProvisionCloudSiteJob::class);
    $site = Site::query()->where('name', 'Acme API')->firstOrFail();
    expect($site->container_image)->toBeNull();
    expect($site->meta['container']['source']['repo'])->toBe('acme/api');
});
test('source mode detection renders runtime panel', function () {
    $user = ownerWithOrg();
    fakeClonerProducingNodeRepo('node server.js');

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->call('detectFromRepository')
        ->assertSee('node')
        ->assertSee('confidence');
});
test('source mode detection prefills container port', function () {
    $user = ownerWithOrg();
    fakeClonerProducingNodeRepo('node server.js --port 4321');

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('port', 4321);
});
test('source mode detection does not overwrite typed port', function () {
    $user = ownerWithOrg();
    fakeClonerProducingNodeRepo('node server.js --port 4321');

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        // Typing a port marks it touched — detection must not stomp it.
        ->set('port', 9000)
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('port', 9000);
});
test('source mode detection failure does not block deploy', function () {
    Queue::fake();
    $user = ownerWithOrg();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $this->app->instance(GitCloner::class, new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            throw new GitCloneException('Repository not found.');
        }
    });
    unset($this->app[RepositoryRuntimePreview::class]);

    $component = Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('mode', 'source')
        ->set('name', 'Acme API')
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->set('port', 8080)
        ->set('region', 'nyc')
        ->set('backend', 'digitalocean_app_platform')
        ->call('detectFromRepository');

    expect($component->get('detectedPlan')['error'])->toBe('Repository not found.');

    $component->call('deploy')->assertHasNoErrors();
    Queue::assertPushed(ProvisionCloudSiteJob::class);
});
function fakeClonerProducingNodeRepo(string $startScript): void
{
    $this->app->instance(GitCloner::class, new class($startScript) implements GitCloner
    {
        public function __construct(private string $startScript) {}

        public function shallowClone(string $url, string $branch, string $destination): void
        {
            mkdir($destination, 0o755, true);
            file_put_contents(
                $destination.'/package.json',
                json_encode([
                    'name' => 'acme-api',
                    'dependencies' => ['express' => '^4.0'],
                    'scripts' => ['start' => $this->startScript],
                ]),
            );
        }
    });

    // RepositoryRuntimePreview is constructed per-request; rebinding the
    // GitCloner is enough — the concern resolves the preview fresh.
    unset($this->app[RepositoryRuntimePreview::class]);
}
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
