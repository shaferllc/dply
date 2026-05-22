<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionEdgeSiteJob;
use App\Livewire\Edge\Create as EdgeCreate;
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
use Tests\TestCase;

class EdgeCreatePageTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.edge'];

    public function test_page_renders_with_no_backends_connected_warning(): void
    {
        config(['server_provision_fake.env_flag' => false]);
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('edge.create'));

        $response->assertOk()
            ->assertSee('Deploy a container app')
            ->assertSee('No container backend connected')
            ->assertSee('Connect DigitalOcean')
            ->assertSee('Connect AWS App Runner');
    }

    public function test_page_shows_fake_cloud_notice_instead_of_warning_when_no_creds_and_fake_on(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('edge.create'));

        $response->assertOk()
            ->assertSee('Fake-cloud mode is on')
            ->assertDontSee('No container backend connected');
    }

    public function test_page_hides_warning_when_backend_connected(): void
    {
        $user = $this->ownerWithOrg();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $response = $this->actingAs($user)->get(route('edge.create'));

        $response->assertOk()
            ->assertDontSee('No container backend connected');
    }

    public function test_changing_backend_resets_region_to_first_available(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('backend', 'aws_app_runner')
            ->assertSet('region', 'us-east-1')
            ->set('backend', 'digitalocean_app_platform')
            ->assertSet('region', 'ams');
    }

    public function test_deploy_dispatches_provision_job_and_redirects(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('name', 'Acme API')
            ->set('image', 'ghcr.io/acme/api:v1')
            ->set('port', 8080)
            ->set('region', 'nyc')
            ->set('backend', 'digitalocean_app_platform')
            ->call('deploy')
            ->assertHasNoErrors();

        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    public function test_deploy_with_no_credential_shows_toast_error(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('name', 'Lonely')
            ->set('image', 'nginx:1')
            ->set('region', 'nyc')
            ->set('backend', 'auto')
            ->call('deploy')
            ->assertDispatched('notify');
    }

    public function test_deploy_validates_required_fields(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->call('deploy')
            ->assertHasErrors(['name', 'image']);
    }

    public function test_source_tab_renders_repo_inputs(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->assertSee('GitHub repo')
            ->assertSee('Branch')
            ->assertSee('Auto-deploy on push to this branch')
            ->assertSee('owner/name or full GitHub URL');
    }

    public function test_source_mode_validates_repo_and_branch(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('name', 'svc')
            ->call('deploy')
            ->assertHasErrors(['repo']);
    }

    public function test_source_mode_warns_when_aws_lacks_github_connection(): void
    {
        $user = $this->ownerWithOrg();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'aws_app_runner',
            'name' => 'AWS',
            'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's'],
        ]);

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('backend', 'aws_app_runner')
            ->assertSee('AWS App Runner needs a GitHub connection');
    }

    public function test_source_mode_skips_warning_when_aws_has_github_connection(): void
    {
        $user = $this->ownerWithOrg();
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
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('backend', 'aws_app_runner')
            ->assertDontSee('AWS App Runner needs a GitHub connection');
    }

    public function test_source_tab_shows_only_manual_entry_when_no_accounts_linked(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->assertSet('linkedSourceControlAccounts', [])
            ->assertSee('owner/name or full GitHub URL')
            ->assertDontSee('Pick from connected account');
    }

    public function test_source_tab_renders_picker_when_accounts_linked(): void
    {
        $user = $this->ownerWithOrg();

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
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->assertSee('Pick from connected account')
            ->assertSee('Enter manually')
            ->assertSee('github:acme');
    }

    public function test_picker_selection_populates_repo_and_branch(): void
    {
        $user = $this->ownerWithOrg();
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
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('repository_selection', 'https://github.com/acme/api.git')
            ->assertSet('repo', 'acme/api')
            ->assertSet('branch', 'develop');
    }

    public function test_source_mode_dispatches_provision_with_source_meta(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('name', 'Acme API')
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->set('port', 8080)
            ->set('region', 'nyc')
            ->set('backend', 'digitalocean_app_platform')
            ->call('deploy')
            ->assertHasNoErrors();

        Queue::assertPushed(ProvisionEdgeSiteJob::class);
        $site = Site::query()->where('name', 'Acme API')->firstOrFail();
        $this->assertNull($site->container_image);
        $this->assertSame('acme/api', $site->meta['container']['source']['repo']);
    }

    public function test_source_mode_detection_renders_runtime_panel(): void
    {
        $user = $this->ownerWithOrg();
        $this->fakeClonerProducingNodeRepo('node server.js');

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSee('node')
            ->assertSee('confidence');
    }

    public function test_source_mode_detection_prefills_container_port(): void
    {
        $user = $this->ownerWithOrg();
        $this->fakeClonerProducingNodeRepo('node server.js --port 4321');

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSet('port', 4321);
    }

    public function test_source_mode_detection_does_not_overwrite_typed_port(): void
    {
        $user = $this->ownerWithOrg();
        $this->fakeClonerProducingNodeRepo('node server.js --port 4321');

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            // Typing a port marks it touched — detection must not stomp it.
            ->set('port', 9000)
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSet('port', 9000);
    }

    public function test_source_mode_detection_failure_does_not_block_deploy(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
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
            ->test(EdgeCreate::class)
            ->set('mode', 'source')
            ->set('name', 'Acme API')
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->set('port', 8080)
            ->set('region', 'nyc')
            ->set('backend', 'digitalocean_app_platform')
            ->call('detectFromRepository');

        $this->assertSame('Repository not found.', $component->get('detectedPlan')['error']);

        $component->call('deploy')->assertHasNoErrors();
        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    private function fakeClonerProducingNodeRepo(string $startScript): void
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

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
