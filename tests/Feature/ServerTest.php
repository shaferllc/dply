<?php

namespace Tests\Feature;

use App\Actions\Sites\CreateContainerSiteFromInspection;
use App\Enums\SiteType;
use App\Jobs\FinalizeContainerCloudLaunchJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Launches\LocalDocker;
use App\Livewire\Servers\Create as ServersCreate;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\ProvisionJourney;
use App\Livewire\Servers\WorkspaceLogs;
use App\Livewire\Servers\WorkspaceManage;
use App\Livewire\Servers\WorkspaceSettings;
use App\Livewire\Servers\WorkspaceSites;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerFirewallRule;
use App\Models\ServerProvisionRun;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDomain;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Models\UserSshKey;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Services\Deploy\LocalRepositoryInspector;
use App\Services\Sites\Contracts\SiteRuntimeProvisioner;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteRuntimeProvisionerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ServerTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_servers_index_redirects_guest(): void
    {
        $response = $this->get(route('servers.index'));

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_servers_index_is_displayed_for_authenticated_user(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('Provision hosts', false);
        $response->assertSee('Open launchpad');
        $response->assertSee(route('launches.create'), false);
        $response->assertSee('No servers yet');
        $response->assertSee('Create a server');
        $response->assertSee(route('servers.create'), false);
        $response->assertSee('Create a VM from here once a cloud provider is connected', false);
    }

    public function test_servers_index_prompts_for_provider_setup_when_no_provider_credentials_exist(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('Set up a provider');
        $response->assertSee('Add provider credentials before you provision infrastructure.');
    }

    public function test_servers_index_lists_servers_in_current_organization(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'My Server',
        ]);

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('My Server');
    }

    public function test_servers_index_search_filters_by_name(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'demo-alpha-unique-xyz',
        ]);
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'demo-beta-unique-xyz',
        ]);

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('search', 'alpha-unique')
            ->assertSee('demo-alpha-unique-xyz')
            ->assertDontSee('demo-beta-unique-xyz');
    }

    public function test_servers_index_status_filter_limits_rows(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'srv-ready-filter-xyz',
            'status' => Server::STATUS_READY,
        ]);
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'srv-error-filter-xyz',
            'status' => Server::STATUS_ERROR,
        ]);

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('statusFilter', Server::STATUS_ERROR)
            ->assertSee('srv-error-filter-xyz')
            ->assertDontSee('srv-ready-filter-xyz');
    }

    public function test_servers_index_reset_filters_clears_state(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('search', 'anything')
            ->set('statusFilter', Server::STATUS_READY)
            ->set('sort', 'name')
            ->set('viewMode', 'grid')
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', '')
            ->assertSet('sort', 'created_at')
            ->assertSet('viewMode', 'list');
    }

    public function test_servers_index_destroy_accepts_string_ulid_and_deletes(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $id = (string) $server->getKey();

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->call('openRemoveServerModal', $id)
            ->call('submitRemoveServer');

        $this->assertModelMissing($server);
    }

    public function test_servers_create_requires_organization(): void
    {
        $user = User::factory()->create();
        // No organization, no session

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertForbidden();
    }

    public function test_launchpad_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.create'));

        $response->assertOk();
        $response->assertSee('Launch setup');
        $response->assertSee('Bring your own server');
        $response->assertSee('Containers');
        $response->assertSee('Edge');
        $response->assertSee('Cloud');
        $response->assertSee('Serverless');
        $response->assertSee('Coming soon');
        $response->assertSee(route('servers.create'), false);
        $response->assertDontSee(route('launches.containers'), false);
        $response->assertDontSee(route('launches.serverless'), false);
        $response->assertDontSee(route('launches.edge-network'), false);
        $response->assertDontSee(route('launches.cloud-network'), false);
    }

    public function test_serverless_launch_path_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.serverless'));

        $response->assertOk();
        $response->assertSee('Serverless');
        $response->assertSee('AWS Lambda');
        $response->assertSee('DigitalOcean Functions');
    }

    public function test_kubernetes_launch_path_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.kubernetes'));

        $response->assertOk();
        $response->assertSee('Kubernetes');
        $response->assertSee('DigitalOcean Kubernetes');
        $response->assertSee('Remote Kubernetes');
    }

    public function test_containers_launch_path_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.containers'));

        $response->assertOk();
        $response->assertSee('Containers');
        $response->assertSee('Shared runtime model');
        $response->assertSee('Local Docker');
        $response->assertSee('Remote Kubernetes');
        $response->assertSee(route('launches.local-docker', absolute: false), false);
        $response->assertDontSee('host_target=docker', false);
        $response->assertDontSee('source=launches.containers', false);
    }

    public function test_servers_create_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();
        UserSshKey::factory()->create([
            'user_id' => $user->id,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('z', 43).' create-test',
        ]);

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertOk();
        $response->assertSee('Create BYO server');
        $response->assertSee('Bring your own server');
        $response->assertSee('Use an existing server');
        $response->assertSee('Provision with a provider');
    }

    public function test_servers_create_can_start_from_containers_docker_path(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.create', [
            'host_target' => 'docker',
            'source' => 'launches.containers',
        ]));

        $response->assertOk();
        $response->assertSee('Remote Docker path');
        $response->assertSee('Create the remote Docker host first');
        $response->assertSee('Docker host');
    }

    public function test_containers_launch_path_lists_existing_local_targets(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();

        $dockerHost = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'orbstack-docker',
            'provider' => 'custom',
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('launches.containers'));

        $response->assertOk();
        $response->assertSee('Continue on an existing local target');
        $response->assertSee('orbstack-docker');
        $response->assertSee(route('sites.create', $dockerHost), false);
    }

    public function test_containers_launch_path_links_to_repo_first_local_docker_flow(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.containers'));

        $response->assertOk();
        $response->assertSee(route('launches.local-docker', absolute: false), false);
        $response->assertSee('Open local Docker');
    }

    public function test_local_docker_repo_first_page_is_displayed(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.local-docker'));

        $response->assertOk();
        $response->assertSee('Repo-first containers');
        $response->assertSee('Repository URL');
        $response->assertSee('Inspect repository');
        $response->assertDontSee('IP address');
        $response->assertDontSee('SSH private key');
    }

    public function test_local_docker_repo_first_flow_auto_creates_hidden_host_and_site(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult()) extends LocalRepositoryInspector
        {
            /**
             * @param  array<string, mixed>  $result
             */
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('repository_branch', 'main')
            ->call('launch')
            ->assertHasNoErrors()
            ->assertRedirect();

        $server = Server::query()
            ->where('organization_id', $organization->id)
            ->where('provider', 'custom')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($server);
        $this->assertTrue($server->isDockerHost());

        $site = Site::query()
            ->where('server_id', $server->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($site);
        $this->assertTrue($site->usesDockerRuntime());
        $this->assertSame('https://github.com/acme/demo.git', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame('laravel', data_get($site->meta, 'docker_runtime.detected.framework'));
        $this->assertStringContainsString('APP_KEY=base64:', (string) $site->env_file_content);
        $this->assertSame('docker', data_get($server->meta, 'local_runtime.mode'));

        Bus::assertChained([
            ProvisionSiteJob::class,
            RunSiteDeploymentJob::class,
        ]);
    }

    public function test_local_docker_repo_first_flow_creates_kubernetes_target_when_repo_detects_cluster_markers(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult(['target_runtime' => 'kubernetes_web', 'target_kind' => 'kubernetes', 'kubernetes_namespace' => 'local-kube', 'detected_files' => ['k8s/deployment.yaml']])) extends LocalRepositoryInspector
        {
            /**
             * @param  array<string, mixed>  $result
             */
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('repository_branch', 'main')
            ->call('launch')
            ->assertHasNoErrors()
            ->assertRedirect();

        $server = Server::query()
            ->where('organization_id', $organization->id)
            ->latest('created_at')
            ->first();

        $site = Site::query()
            ->where('server_id', $server?->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($server);
        $this->assertSame(Server::HOST_KIND_KUBERNETES, data_get($server->meta, 'host_kind'));
        $this->assertNotNull($site);
        $this->assertTrue($site->usesKubernetesRuntime());
        $this->assertSame('local-kube', data_get($site->meta, 'kubernetes_runtime.namespace'));

        Bus::assertChained([
            ProvisionSiteJob::class,
            RunSiteDeploymentJob::class,
        ]);
    }

    public function test_local_docker_repo_first_flow_stores_low_confidence_detection_metadata(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult(['framework' => 'unknown', 'language' => 'unknown', 'confidence' => 'low', 'warnings' => ['Review runtime details after launch.'], 'reasons' => ['No clear framework markers were detected in the repository root.'], 'env_template' => ['path' => '.env.example', 'keys' => ['APP_NAME']]])) extends LocalRepositoryInspector
        {
            /**
             * @param  array<string, mixed>  $result
             */
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('repository_branch', 'main')
            ->call('launch')
            ->assertHasNoErrors()
            ->assertRedirect();

        $site = Site::query()->latest('created_at')->first();

        $this->assertNotNull($site);
        $this->assertSame('low', data_get($site->meta, 'docker_runtime.detected.confidence'));
        $this->assertSame('.env.example', data_get($site->meta, 'docker_runtime.detected.env_template.path'));
    }

    public function test_local_docker_repo_first_flow_persists_repository_subdirectory_for_runtime_checkout(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult(['repository_subdirectory' => 'apps/web'])) extends LocalRepositoryInspector
        {
            /**
             * @param  array<string, mixed>  $result
             */
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/monorepo.git')
            ->set('repository_branch', 'main')
            ->set('repository_subdirectory', 'apps/web')
            ->call('launch')
            ->assertHasNoErrors()
            ->assertRedirect();

        $site = Site::query()->latest('created_at')->first();

        $this->assertNotNull($site);
        $this->assertSame('apps/web', data_get($site->meta, 'docker_runtime.repository_subdirectory'));
        $this->assertSame('apps/web', data_get($site->meta, 'runtime_target.repository_subdirectory'));
    }

    public function test_local_docker_launch_redirects_to_site_workspace(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult()) extends LocalRepositoryInspector
        {
            /**
             * @param  array<string, mixed>  $result
             */
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        $component = Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('repository_branch', 'main')
            ->call('launch')
            ->assertHasNoErrors()
            ->assertRedirect();

        $site = Site::query()->latest('created_at')->firstOrFail();
        $server = $site->server()->firstOrFail();

        $component
            ->assertRedirect(route('sites.show', [$server, $site], false));
    }

    public function test_local_docker_page_shows_cloud_target_choices_after_inspection(): void
    {
        $user = $this->userWithOrganization();

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult()) extends LocalRepositoryInspector
        {
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->call('inspectRepository')
            ->assertSee('Choose the container target')
            ->assertSee('Remote Docker (DigitalOcean)')
            ->assertSee('Remote Kubernetes (AWS)');
    }

    public function test_local_docker_can_launch_digitalocean_docker_target_and_queue_finalizer(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'token'],
        ]);

        app()->instance(LocalRepositoryInspector::class, new class($this->localInspectionResult()) extends LocalRepositoryInspector
        {
            public function __construct(private array $result) {}

            public function inspect(string $repositoryUrl, string $branch = 'main', string $subdirectory = '', int|string|null $userId = null, ?string $sourceControlAccountId = null): array
            {
                return $this->result;
            }
        });

        Livewire::actingAs($user)
            ->test(LocalDocker::class)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('target_family', 'digitalocean_docker')
            ->set('provider_credential_id', (string) $credential->id)
            ->set('cloud_region', 'nyc3')
            ->set('cloud_size', 's-1vcpu-1gb')
            ->call('launch')
            ->assertHasNoErrors()
            ->assertRedirect();

        $server = Server::query()->where('provider', 'digitalocean')->latest('created_at')->first();

        $this->assertNotNull($server);
        $this->assertSame(Server::HOST_KIND_DOCKER, data_get($server->meta, 'host_kind'));

        Queue::assertPushed(ProvisionDigitalOceanDropletJob::class);
        Queue::assertPushed(FinalizeContainerCloudLaunchJob::class);
    }

    public function test_finalize_container_cloud_launch_job_creates_site_after_server_is_ready(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'provider' => 'aws',
            'credentials' => [
                'access_key_id' => 'key',
                'secret_access_key' => 'secret',
            ],
        ]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'aws',
            'provider_credential_id' => $credential->id,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);

        $inspection = $this->localInspectionResult();

        $job = new FinalizeContainerCloudLaunchJob(
            (string) $server->id,
            (string) $user->id,
            (string) $organization->id,
            $inspection,
            'aws_docker',
        );

        $job->handle(app(CreateContainerSiteFromInspection::class));

        $site = Site::query()->where('server_id', $server->id)->latest('created_at')->first();

        $this->assertNotNull($site);
        $this->assertSame('aws_docker', data_get($site->meta, 'runtime_target.family'));

        Bus::assertChained([
            ProvisionSiteJob::class,
            RunSiteDeploymentJob::class,
        ]);
    }

    public function test_finalize_container_cloud_launch_job_tracks_waiting_for_server_progress(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'token'],
        ]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'digitalocean',
            'provider_credential_id' => $credential->id,
            'status' => Server::STATUS_PROVISIONING,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'container_launch' => [
                    'status' => 'waiting_for_server',
                    'target_family' => 'digitalocean_docker',
                    'events' => [],
                ],
            ],
        ]);

        $job = new FinalizeContainerCloudLaunchJob(
            (string) $server->id,
            (string) $user->id,
            (string) $organization->id,
            $this->localInspectionResult(),
            'digitalocean_docker',
        );

        $job->handle(app(CreateContainerSiteFromInspection::class));

        $server->refresh();

        $this->assertSame('waiting_for_server', data_get($server->meta, 'container_launch.status'));
        $this->assertSame('Provisioning server', data_get($server->meta, 'container_launch.current_step_label'));
        $this->assertSame('digitalocean_docker', data_get($server->meta, 'container_launch.target_family'));
        $this->assertNotEmpty(data_get($server->meta, 'container_launch.events', []));
        $this->assertSame(
            'Waiting for the remote server to finish provisioning before creating the site.',
            data_get($server->meta, 'container_launch.events.0.message')
        );
    }

    public function test_pending_site_install_page_shows_install_activity_log(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => Server::STATUS_READY,
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => Site::STATUS_PENDING,
            'meta' => [
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'pending',
                    'logs' => [],
                ],
                'docker_runtime' => [
                    'app_type' => 'php',
                    'auto_created' => true,
                ],
                'provisioning' => [
                    'state' => 'awaiting_first_deploy',
                    'log' => [
                        [
                            'at' => now()->subMinute()->toIso8601String(),
                            'level' => 'info',
                            'step' => 'preparing_runtime_artifacts',
                            'message' => 'Preparing runtime artifacts for the selected container target.',
                            'context' => [
                                'runtime_profile' => 'docker_web',
                                'target_family' => 'local_orbstack_docker',
                            ],
                        ],
                        [
                            'at' => now()->toIso8601String(),
                            'level' => 'info',
                            'step' => 'configuring_publication',
                            'message' => 'Publication target prepared for the first deploy.',
                            'context' => [
                                'published_url' => 'http://127.0.0.1:8080',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('sites.show', [$server, $site]))
            ->assertOk()
            ->assertSee('Install activity')
            ->assertSee('Preparing runtime artifacts for the selected container target.')
            ->assertSee('Publication target prepared for the first deploy.')
            ->assertSee('target family')
            ->assertSee('local_orbstack_docker')
            ->assertSee('published url')
            ->assertSee('http://127.0.0.1:8080');
    }

    public function test_servers_overview_shows_container_launch_progress_card_before_site_is_ready(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'container_launch' => [
                    'status' => 'waiting_for_site_provisioning',
                    'target_family' => 'digitalocean_docker',
                    'repository_url' => 'https://github.com/acme/demo.git',
                    'repository_branch' => 'main',
                    'repository_subdirectory' => 'apps/web',
                    'current_step_label' => 'Provisioning site workspace',
                    'summary' => 'The server is ready. Dply is now creating the site and preparing the first deployment workflow.',
                    'events' => [
                        [
                            'at' => now()->subMinute()->toIso8601String(),
                            'level' => 'info',
                            'message' => 'Remote server is ready. Creating the site from the inspected repository.',
                        ],
                        [
                            'at' => now()->toIso8601String(),
                            'level' => 'info',
                            'message' => 'Site created. Provisioning and first deployment have been queued.',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertSee('Container launch in progress');
        $response->assertSee('Provisioning site workspace');
        $response->assertSee('digitalocean_docker');
        $response->assertSee('apps/web');
        $response->assertSee('Remote server is ready. Creating the site from the inspected repository.');
        $response->assertSee('Site created. Provisioning and first deployment have been queued.');
    }

    public function test_site_provisioner_records_docker_runtime_activity_details(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => Server::STATUS_READY,
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => Site::STATUS_PENDING,
            'git_repository_url' => 'https://github.com/acme/demo.git',
            'git_branch' => 'main',
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'pending',
                    'logs' => [],
                ],
                'docker_runtime' => [
                    'app_type' => 'php',
                    'auto_created' => true,
                ],
            ],
        ]);

        app()->instance(SiteRuntimeProvisionerRegistry::class, new SiteRuntimeProvisionerRegistry([
            new class implements SiteRuntimeProvisioner
            {
                public function runtimeProfile(): string
                {
                    return 'docker_web';
                }

                public function provision(Site $site): void
                {
                    $meta = is_array($site->meta) ? $site->meta : [];
                    $meta['docker_runtime'] = array_merge(
                        is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [],
                        ['compose_yaml' => "services:\n  app:\n    image: demo\n"]
                    );
                    $meta['runtime_target'] = array_merge(
                        is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : [],
                        [
                            'publication' => [
                                'status' => 'pending',
                                'hostname' => 'demo.local.dply.test',
                                'url' => 'http://127.0.0.1:8080',
                                'port' => 8080,
                            ],
                        ]
                    );

                    $site->forceFill(['meta' => $meta])->save();
                }

                public function readyResult(Site $site): array
                {
                    return [
                        'ok' => false,
                        'hostname' => 'demo.local.dply.test',
                        'url' => 'http://127.0.0.1:8080',
                        'error' => 'Waiting for first deploy.',
                        'checked_at' => now()->toIso8601String(),
                        'checks' => [],
                    ];
                }
            },
        ]));

        app(SiteProvisioner::class)->begin($site->fresh());

        $messages = collect($site->fresh()->provisioningLog())->pluck('message')->all();

        $this->assertContains('Preparing runtime artifacts for the selected container target.', $messages);
        $this->assertContains('Runtime artifact generation finished.', $messages);
        $this->assertContains('Publication target prepared for the first deploy.', $messages);

        $publicationLog = collect($site->fresh()->provisioningLog())
            ->firstWhere('message', 'Publication target prepared for the first deploy.');

        $this->assertSame('http://127.0.0.1:8080', data_get($publicationLog, 'context.published_url'));
        $this->assertSame(8080, data_get($publicationLog, 'context.published_port'));
        $this->assertSame('demo.local.dply.test', data_get($publicationLog, 'context.publication_hostname'));
    }

    public function test_servers_create_shows_profile_ssh_key_management_link_when_user_has_no_key(): void
    {
        $user = $this->userWithOrganization();

        $this->actingAs($user)
            ->get(route('servers.create'))
            ->assertOk()
            ->assertSee('Create BYO server')
            ->assertSee(route('profile.ssh-keys', absolute: false), false)
            ->assertSee('Manage profile SSH keys');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function localInspectionResult(array $overrides = []): array
    {
        return array_replace_recursive([
            'repository_url' => 'https://github.com/acme/demo.git',
            'repository_branch' => 'main',
            'slug' => 'demo',
            'name' => 'Demo',
            'inspection_output' => 'ok',
            'detection' => [
                'target_runtime' => 'docker_web',
                'target_kind' => 'docker',
                'site_type' => SiteType::Php,
                'framework' => 'laravel',
                'language' => 'php',
                'confidence' => 'high',
                'document_root' => '/var/www/demo/public',
                'repository_path' => '/var/www/demo',
                'app_port' => null,
                'kubernetes_namespace' => null,
                'reasons' => ['Detected Laravel project files.'],
                'warnings' => [],
                'detected_files' => ['artisan', 'composer.json'],
                'env_template' => [
                    'path' => null,
                    'keys' => [],
                ],
            ],
        ], [
            'detection' => $overrides,
        ]);
    }

    public function test_servers_create_shows_provider_provisioning_option_when_provider_credentials_exist(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'token'],
        ]);

        $this->actingAs($user)
            ->get(route('servers.create'))
            ->assertOk()
            ->assertSee('Bring your own server')
            ->assertSee('Provision with a provider')
            ->assertDontSee('Choose provider')
            ->assertDontSee('Choose account');
    }

    public function test_servers_create_shows_provider_provisioning_option_even_without_provider_credentials(): void
    {
        $user = $this->userWithOrganization();
        UserSshKey::factory()->create([
            'user_id' => $user->id,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('y', 43).' provider-test',
        ]);

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertOk();
        $response->assertSee('Bring your own server');
        $response->assertSee('Provision with a provider');
        $response->assertDontSee('Custom server details');
        $response->assertDontSee('SSH private key (PEM / OpenSSH)');
        $response->assertDontSee('Choose provider');
    }

    public function test_servers_create_defaults_to_no_path_until_operator_selects_one(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        UserSshKey::factory()->create([
            'user_id' => $user->id,
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('b', 43).' blocked-size',
        ]);

        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'token'],
        ]);

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->assertSee('Use an existing server')
            ->assertSee('Provision with a provider')
            ->assertSee('Bring your own server')
            ->assertDontSee('SSH private key (PEM / OpenSSH)')
            ->assertDontSee('Choose provider')
            ->assertSet('createMode', '')
            ->assertSet('form.type', 'custom')
            ->call('useExistingServerPath')
            ->assertSee('SSH private key (PEM / OpenSSH)')
            ->assertSee('Test connection')
            ->assertSet('createMode', 'existing');
    }

    public function test_servers_create_can_switch_to_provider_provisioning_path(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'abc']], 200),
            'https://api.digitalocean.com/v2/regions' => Http::response(['regions' => [['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true]]]),
            'https://api.digitalocean.com/v2/sizes' => Http::response(['sizes' => [['slug' => 's-1vcpu-1gb', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25, 'price_monthly' => 6, 'available' => true]]]),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'token'],
        ]);

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'digitalocean')
            ->set('form.provider_credential_id', (string) $credential->id)
            ->assertSet('form.type', 'digitalocean')
            ->assertSee('Choose account')
            ->assertSee('Region')
            ->assertSee('Droplet size')
            ->assertSee('Core server config')
            ->assertSee('Advanced options');
    }

    public function test_servers_create_generates_a_name_and_can_regenerate_it(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'token'],
        ]);

        $component = Livewire::actingAs($user)->test(ServersCreate::class);

        $initial = $component->get('form.name');

        $this->assertNotSame('', $initial);

        $component->call('regenerateServerName');

        $regenerated = $component->get('form.name');

        $this->assertNotSame('', $regenerated);
        $this->assertNotSame($initial, $regenerated);
    }

    public function test_servers_create_install_profile_updates_stack_defaults(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'abc']], 200),
            'https://api.digitalocean.com/v2/regions' => Http::response(['regions' => [['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true]]]),
            'https://api.digitalocean.com/v2/sizes' => Http::response(['sizes' => [['slug' => 's-1vcpu-1gb', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25, 'price_monthly' => 6, 'available' => true]]]),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'token'],
        ]);

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.install_profile', 'queue_worker')
            ->assertSet('form.server_role', 'worker')
            ->assertSet('form.webserver', 'none')
            ->assertSet('form.database', 'none');
    }

    public function test_servers_can_be_stored_as_custom(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'custom')
            ->set('form.name', 'Custom Box')
            ->set('form.ip_address', '192.168.1.1')
            ->set('form.ssh_port', '22')
            ->set('form.ssh_user', 'root')
            ->set('form.ssh_private_key', "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----")
            ->call('store')
            ->assertRedirect();

        $server = Server::query()->where('name', 'Custom Box')->firstOrFail();

        $this->assertDatabaseHas('servers', [
            'name' => 'Custom Box',
            'organization_id' => $org->id,
            'provider' => 'custom',
            'status' => 'ready',
        ]);

        $this->assertSame('application', data_get($server->meta, 'server_role'));
        $this->assertSame('laravel_app', data_get($server->meta, 'install_profile'));
        $this->assertSame(Server::HOST_KIND_VM, data_get($server->meta, 'host_kind'));
        $this->assertTrue(RunSetupScriptJob::shouldDispatch($server));
        Queue::assertPushed(WaitForServerSshReadyJob::class);
    }

    public function test_servers_can_be_stored_as_custom_docker_hosts(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'custom')
            ->set('form.custom_host_kind', 'docker')
            ->set('form.name', 'Docker Box')
            ->set('form.ip_address', '192.168.1.2')
            ->set('form.ssh_port', '22')
            ->set('form.ssh_user', 'root')
            ->set('form.ssh_private_key', "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----")
            ->call('store')
            ->assertRedirect();

        $server = Server::query()->where('name', 'Docker Box')->firstOrFail();

        $this->assertSame($org->id, $server->organization_id);
        $this->assertSame(Server::HOST_KIND_DOCKER, data_get($server->meta, 'host_kind'));
        $this->assertTrue($server->isDockerHost());
        $this->assertFalse(RunSetupScriptJob::shouldDispatch($server));
    }

    public function test_servers_create_custom_path_shows_warning_preflight_and_unavailable_cost(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->call('useExistingServerPath')
            ->assertSee('Preflight and cost preview')
            ->assertSee('Stack selection ready')
            ->assertSee('SSH reachability is not verified yet')
            ->assertSee('Dply cannot estimate pricing for your own VPS.')
            ->assertSee('Unavailable');
    }

    public function test_servers_create_custom_connection_test_can_report_success(): void
    {
        $sshMock = Mockery::mock('overload:App\Services\SshConnection');
        $sshMock->shouldReceive('connect')->once()->with(8)->andReturn(true);
        $sshMock->shouldReceive('exec')->once()->with('whoami', 8)->andReturn('root');

        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'custom')
            ->set('form.ip_address', '203.0.113.10')
            ->set('form.ssh_port', '22')
            ->set('form.ssh_user', 'root')
            ->set('form.ssh_private_key', "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----")
            ->call('testCustomConnection')
            ->assertSet('customConnectionTestState', 'success')
            ->assertSee('SSH connection verified as root.');
    }

    public function test_servers_create_defaults_to_custom_type_even_when_provider_credentials_exist(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'token'],
        ]);

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->assertSet('form.type', 'custom')
            ->assertSet('form.custom_host_kind', 'vm')
            ->assertSet('form.ssh_port', '22')
            ->assertSet('form.ssh_user', 'root');
    }

    public function test_servers_create_blocks_store_when_required_connection_details_are_missing(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $component = Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.name', 'Blocked Box');

        $component
            ->call('store')
            ->assertHasErrors(['ip_address', 'ssh_private_key']);

        $this->assertDatabaseMissing('servers', [
            'organization_id' => $org->id,
            'name' => 'Blocked Box',
        ]);
    }

    public function test_servers_show_routes_provisioning_server_to_journey_page(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_PENDING,
            'setup_status' => Server::SETUP_STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', $server))
            ->assertRedirect(route('servers.journey', $server));
    }

    public function test_servers_show_routes_ready_server_to_overview_page(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', $server))
            ->assertRedirect(route('servers.overview', $server));
    }

    public function test_servers_show_routes_ready_server_with_incomplete_setup_to_journey_page(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_FAILED,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', $server))
            ->assertRedirect(route('servers.journey', $server));
    }

    public function test_servers_journey_page_renders_active_pending_and_completed_steps(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'ip_address' => '203.0.113.10',
        ]);

        $task = Task::query()->create([
            'name' => 'Provision server',
            'action' => 'script',
            'script' => 'echo setup',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'output' => "Installing packages\nConfiguring nginx\n",
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subSeconds(21),
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Installation tasks');
        $response->assertSee('Running server setup');
        $response->assertSee('Pending tasks');
        $response->assertSee('Completed tasks');
        $response->assertSee('Provisioning server');
        $response->assertSee('Waiting for SSH');
        $response->assertSee('Request queued with provider');
        $response->assertSee('Installing packages');
    }

    public function test_servers_journey_page_uses_provision_script_step_markers_when_present(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'ip_address' => '203.0.113.10',
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'output' => implode("\n", [
                '[dply-step] Checking server status',
                'Checking existing packages',
                '[dply-step] Testing server connection',
                'Connection established',
                '[dply-step] Installing system updates',
                'Hit:1 ubuntu packages',
            ]),
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subSeconds(21),
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Checking server status');
        $response->assertSee('Testing server connection');
        $response->assertSee('Installing system updates');
        $response->assertDontSee('Running server setup');
        $response->assertSee('Checking existing packages');
        $response->assertSee('Connection established');
        $response->assertSee('Hit:1 ubuntu packages');
    }

    public function test_servers_journey_page_shows_persisted_output_for_completed_steps(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'ip_address' => '203.0.113.10',
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'script_content' => implode("\n", [
                "echo '[dply-step] Checking server status'",
                "echo '[dply-step] Creating server user'",
                "echo '[dply-step] Installing nginx'",
            ]),
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'output' => implode("\n", [
                '[dply-step] Installing nginx',
                'Reading package lists',
            ]),
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subSeconds(21),
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
                'provision_step_snapshots' => [
                    'script_'.md5('Checking server status') => [
                        'label' => 'Checking server status',
                        'output' => 'Server is reachable',
                    ],
                    'script_'.md5('Creating server user') => [
                        'label' => 'Creating server user',
                        'output' => implode("\n", [
                            'Adding deploy user',
                            'Granting sudo access',
                        ]),
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Creating server user');
        $response->assertSee('Adding deploy user');
        $response->assertSee('Granting sudo access');
    }

    public function test_servers_journey_page_marks_skipped_install_step_as_skipped(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'ip_address' => '203.0.113.10',
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'script_content' => implode("\n", [
                "echo '[dply-step] Installing Redis'",
                "echo '[dply-step] Finalizing server'",
            ]),
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'output' => implode("\n", [
                '[dply-step] Finalizing server',
                'Firewall is active and enabled on system startup',
            ]),
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subSeconds(21),
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
                'provision_step_snapshots' => [
                    'script_'.md5('Installing Redis') => [
                        'label' => 'Installing Redis',
                        'output' => '[dply] redis-server already installed; skipping package install.',
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Installing Redis');
        $response->assertSee('Skipped because the required software was already installed.');
        $response->assertSee('already installed; skipping package install.');
    }

    public function test_servers_journey_page_marks_persisted_skipped_steps_as_completed(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $phpStepKey = 'script_'.md5('Installing PHP 8.3');
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => [
                'provision_step_snapshots' => [
                    $phpStepKey => [
                        'label' => 'Installing PHP 8.3',
                        'output' => '[dply] php already installed; skipping package install.',
                    ],
                ],
            ],
        ]);

        $task = Task::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'script_content' => implode("\n", [
                'echo "[dply-step] Installing system updates"',
                'echo "[dply-step] Installing PHP 8.3"',
                'echo "[dply-step] Installing MySQL"',
            ]),
            'output' => '',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Pending,
            'server_id' => $server->id,
            'created_by' => $user->id,
        ]);

        $meta = $server->meta ?? [];
        $meta['provision_task_id'] = $task->id;
        $server->update(['meta' => $meta]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Completed tasks');
        $response->assertSee('Installing PHP 8.3');
        $response->assertSee('Skipped because the required software was already installed.');
    }

    public function test_servers_journey_page_renders_pending_state_copy(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_PENDING,
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'ip_address' => null,
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Request queued with provider');
        $response->assertSee('Provisioning server');
        $response->assertSee('Your request has been accepted and is waiting to start provisioning.');
        $response->assertSee('Pending tasks');
    }

    public function test_servers_journey_page_renders_failed_state_copy(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_ERROR,
            'setup_status' => Server::SETUP_STATUS_FAILED,
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Failed');
        $response->assertSee('Running server setup');
        $response->assertSee('The server setup task failed before finishing.');
    }

    public function test_servers_journey_page_shows_provision_run_artifacts(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_FAILED,
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Failed,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $run = ServerProvisionRun::query()->create([
            'server_id' => $server->id,
            'task_id' => $task->id,
            'attempt' => 2,
            'status' => 'failed',
            'rollback_status' => 'attempted',
            'summary' => 'Provisioning failed after attempting safe rollback.',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);

        $run->artifacts()->create([
            'type' => 'verification_report',
            'key' => 'verification-report',
            'label' => 'Verification report',
            'content' => '[{"key":"nginx","status":"ok"}]',
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
                'provision_run_id' => (string) $run->id,
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Provision run');
        $response->assertSee('#2');
        $response->assertSee('Rollback');
        $response->assertSee('Verification report');
    }

    public function test_servers_journey_page_renders_verification_repair_and_stack_summary_cards(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_FAILED,
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Failed,
            'output' => 'haproxy -c failed',
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subMinutes(3),
            'completed_at' => now(),
        ]);

        $run = ServerProvisionRun::query()->create([
            'server_id' => $server->id,
            'task_id' => $task->id,
            'attempt' => 1,
            'status' => 'failed',
            'rollback_status' => 'repair_required',
            'summary' => 'Provisioning failed and needs guided repair.',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $run->artifacts()->create([
            'type' => 'verification_report',
            'key' => 'verification-report',
            'label' => 'Verification report',
            'metadata' => [
                'checks' => [
                    ['key' => 'haproxy', 'status' => 'failed', 'detail' => 'Config test failed'],
                ],
            ],
            'content' => '[]',
        ]);

        $run->artifacts()->create([
            'type' => 'stack_summary',
            'key' => 'stack-summary',
            'label' => 'Installed stack',
            'metadata' => [
                'role' => 'load_balancer',
                'webserver' => 'none',
                'php_version' => 'none',
                'database' => 'none',
                'cache_service' => 'none',
                'deploy_user' => 'dply',
                'expected_services' => ['haproxy', 'ufw'],
                'paths' => ['current' => '/home/dply/apps/lb/current'],
                'config_files' => ['/etc/haproxy/haproxy.cfg'],
            ],
            'content' => '{}',
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
                'provision_run_id' => (string) $run->id,
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Verification results');
        $response->assertSee('HAProxy config test');
        $response->assertSee('Repair guidance');
        $response->assertSee('Rollback needs repair');
        $response->assertSee('Installed stack');
        $response->assertSee('/etc/haproxy/haproxy.cfg');
    }

    public function test_servers_journey_page_renders_stall_timing_hint_for_active_task(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'ip_address' => '203.0.113.10',
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'script_content' => "echo '[dply-step] Running server setup'",
            'output' => '[dply-step] Running server setup',
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subMinutes(9),
        ]);
        Task::withoutTimestamps(function () use ($task): void {
            $task->update([
                'updated_at' => now()->subMinutes(7),
            ]);
        });

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Run timing');
    }

    public function test_servers_journey_can_restart_install(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_FAILED,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
                'provision_task_id' => 'old-task-id',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ProvisionJourney::class, ['server' => $server])
            ->call('rerunSetup')
            ->assertRedirect(route('servers.journey', $server));

        $server->refresh();

        $this->assertSame(Server::SETUP_STATUS_PENDING, $server->setup_status);
        $this->assertArrayNotHasKey('provision_task_id', $server->meta ?? []);

        Queue::assertPushed(WaitForServerSshReadyJob::class, function (WaitForServerSshReadyJob $job) use ($server) {
            return $job->server->is($server);
        });
    }

    public function test_servers_journey_shows_cancel_build_actions_for_active_task(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'meta' => [
                'server_role' => 'application',
            ],
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subMinute(),
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('servers.journey', $server));

        $response->assertOk();
        $response->assertSee('Cancel build');

        Livewire::actingAs($user)
            ->test(ProvisionJourney::class, ['server' => $server])
            ->call('openCancelProvisionModal')
            ->assertSee('Cancel build and remove server');
    }

    public function test_servers_journey_can_cancel_active_provision_task(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'meta' => [
                'server_role' => 'application',
            ],
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Running,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'started_at' => now()->subMinute(),
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], [
                'provision_task_id' => (string) $task->id,
            ]),
        ]);

        $taskRunner = Mockery::mock(TaskRunnerService::class);
        $taskRunner->shouldReceive('cancelTask')
            ->once()
            ->with((string) $task->id)
            ->andReturnUsing(function () use ($task, $server) {
                $task->update([
                    'status' => TaskStatus::Cancelled,
                    'completed_at' => now(),
                ]);
                $server->update([
                    'setup_status' => Server::SETUP_STATUS_FAILED,
                ]);

                return ['success' => true];
            });
        $this->app->instance(TaskRunnerService::class, $taskRunner);

        Livewire::actingAs($user)
            ->test(ProvisionJourney::class, ['server' => $server])
            ->call('cancelProvision')
            ->assertSet('showCancelProvisionModal', false);

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertSame(Server::SETUP_STATUS_FAILED, $server->fresh()->setup_status);
    }

    public function test_servers_journey_redirects_to_server_overview_once_setup_finishes(): void
    {
        config()->set('app.env', 'production');

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        Livewire::actingAs($user)
            ->test(ProvisionJourney::class, ['server' => $server])
            ->assertRedirect(route('servers.overview', $server));
    }

    public function test_servers_journey_stays_open_locally_after_setup_finishes(): void
    {
        config()->set('app.env', 'local');

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        Livewire::actingAs($user)
            ->test(ProvisionJourney::class, ['server' => $server])
            ->assertNoRedirect();
    }

    public function test_servers_show_is_displayed_for_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Test Server',
        ]);

        $this->actingAs($user)->get(route('servers.show', $server))->assertRedirect(route('servers.overview', $server));

        $response = $this->actingAs($user)->get(route('servers.overview', $server));
        $response->assertOk();
        $response->assertSee('Test Server');
    }

    public function test_servers_overview_links_to_setup_journey_when_provisioning_can_be_rerun(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertSee('Provisioning');
        $response->assertSee('Open setup journey');
        $response->assertSee(route('servers.journey', $server), false);
    }

    public function test_servers_overview_renders_dashboard_summary_for_ready_server(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'App Server',
            'region' => 'nyc1',
            'size' => 's-2vcpu-4gb',
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'forge',
            'ssh_port' => 2222,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
            'health_status' => Server::HEALTH_REACHABLE,
            'last_health_check_at' => now()->subMinutes(10),
            'meta' => [
                'monitoring_last_sample_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);

        $alpha = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Alpha Site',
            'slug' => 'alpha-site',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        $beta = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Beta Site',
            'slug' => 'beta-site',
            'status' => Site::STATUS_PENDING,
        ]);

        $alphaProject = Project::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'Alpha Project',
            'slug' => 'alpha-project',
            'kind' => Project::KIND_BYO_SITE,
        ]);
        $alpha->forceFill(['project_id' => $alphaProject->id])->save();

        SiteDomain::query()->create([
            'site_id' => $alpha->id,
            'hostname' => 'alpha.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        SiteDeployment::query()->create([
            'site_id' => $alpha->id,
            'project_id' => $alphaProject->id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'git_sha' => 'abc123',
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(19),
        ]);

        ServerFirewallRule::query()->create([
            'server_id' => $server->id,
            'name' => 'HTTPS',
            'port' => 443,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        ServerCronJob::query()->create([
            'server_id' => $server->id,
            'cron_expression' => '* * * * *',
            'command' => 'php artisan schedule:run',
            'user' => 'forge',
            'enabled' => true,
            'overlap_policy' => ServerCronJob::OVERLAP_ALLOW,
        ]);

        SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'slug' => 'queue-worker',
            'program_type' => 'queue',
            'command' => 'php artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'is_active' => true,
        ]);

        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'Deploy key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITest deploy@example',
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertSee('Overview');
        $response->assertSee('development-facing summary');
        $response->assertSee('Open Sites');
        $response->assertSee('Open Deploy');
        $response->assertSee('Health');
        $response->assertSee('Sites');
        $response->assertSee('Latest deploy');
        $response->assertSee('Operations');
        $response->assertSee('Alpha Site');
        $response->assertSee('alpha.test');
        $response->assertSee('Beta Site');
        $response->assertSee('1 enabled firewall rule');
        $response->assertSee('1 cron job');
        $response->assertSee('1 daemon');
        $response->assertSee('1 SSH key');
        $response->assertSee('Check health now');
        $response->assertSee('Checking…');
        $response->assertSee('status page');
    }

    public function test_servers_overview_reminds_user_when_ready_server_has_no_personal_profile_key_attached(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        UserSshKey::factory()->create([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('o', 43).' overview-reminder',
        ]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'forge',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertSee('Add your personal SSH key before you need this server');
        $response->assertSee(route('servers.ssh-keys', $server), false);
    }

    public function test_servers_overview_summarizes_foundation_state_across_attached_sites(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'ip_address' => '203.0.113.44',
            'ssh_user' => 'forge',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        $blockedSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Blocked Docker Site',
            'status' => Site::STATUS_DOCKER_CONFIGURED,
            'git_repository_url' => null,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'docker',
                    'platform' => 'byo',
                    'mode' => 'docker',
                    'provider' => 'byo',
                    'status' => 'configured',
                ],
            ],
        ]);

        $driftedSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Drifted App',
            'status' => Site::STATUS_NGINX_ACTIVE,
            'meta' => [
                'deployment_foundation' => [
                    'applied_revisions' => [
                        'runtime' => 'outdated-runtime-revision',
                    ],
                ],
            ],
        ]);

        SiteDomain::query()->create([
            'site_id' => $driftedSite->id,
            'hostname' => 'drifted.example.test',
            'is_primary' => true,
        ]);

        ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'app',
            'engine' => 'mysql',
            'username' => 'app',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertSee('Deployment foundation');
        $response->assertSee('1 blocked site');
        $response->assertSee('1 drifted runtime');
        $response->assertSee('Database');
        $response->assertSee('Configured: 2');
        $response->assertSee('Blocked Docker Site');
        $response->assertSee('1 blocking preflight issue');
        $response->assertSee('Blocked');
        $response->assertSee('Drifted App');
        $response->assertSee('Drift detected');
    }

    public function test_servers_overview_hides_personal_key_reminder_once_server_has_current_users_key(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $profileKey = UserSshKey::factory()->create([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('n', 43).' overview-attached',
        ]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'forge',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'managed_key_type' => UserSshKey::class,
            'managed_key_id' => $profileKey->id,
            'name' => $profileKey->name,
            'public_key' => $profileKey->public_key,
            'target_linux_user' => '',
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertDontSee('Add your personal SSH key before you need this server');
    }

    public function test_servers_overview_hides_personal_key_reminder_when_matching_profile_key_was_added_manually(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $profileKey = UserSshKey::factory()->create([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('v', 43).' overview-manual',
        ]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'forge',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'managed_key_type' => null,
            'managed_key_id' => null,
            'name' => 'Imported manually',
            'public_key' => $profileKey->public_key,
            'target_linux_user' => '',
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk();
        $response->assertDontSee('Add your personal SSH key before you need this server');
    }

    public function test_server_show_logs_tab_renders(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->assertSee('Log source')
            ->assertSee('Dply activity')
            ->assertSee(__('Options'))
            ->assertSee(__('Refresh'))
            ->assertSee(__('Regex'))
            ->assertSee(__('Time'))
            ->assertDontSee(__('Copy'))
            ->assertDontSee(__('Download'))
            ->assertDontSee(__('Export & share'))
            ->assertDontSee(__('Saved views'))
            ->assertDontSee(__('Pinned lines'))
            ->assertDontSee(__('Line hints (visible fetch)'))
            ->assertDontSee(__('How this viewer works'))
            ->call('toggleLogOptionsMenu')
            ->assertSee(__('Lines to tail'))
            ->assertSee(__('Lines visible'))
            ->assertSee(__('Auto-refresh'))
            ->assertSee(__('Reset filter'))
            ->assertSee(__('Clear display'));
    }

    public function test_server_logs_select_log_source_updates_active_key(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server]);

        $keys = array_keys($component->instance()->availableLogSources());
        $this->assertGreaterThanOrEqual(1, count($keys), 'Log sources must include at least one key');

        if (count($keys) < 2) {
            $component->call('selectLogSource', $keys[0])->assertSet('logKey', $keys[0]);

            return;
        }

        $component
            ->call('selectLogSource', $keys[1])
            ->assertSet('logKey', $keys[1]);
    }

    public function test_server_logs_tail_line_count_persists_on_server_meta(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('logTailLines', 150)
            ->set('logDisplayLines', 8)
            ->call('applyLogTailLines');

        $server->refresh();
        $this->assertSame(150, $server->meta['log_ui_tail_lines'] ?? null);
        $this->assertSame(8, $server->meta['log_ui_display_lines'] ?? null);
    }

    public function test_server_logs_clear_display_clears_buffer(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', 'line one')
            ->set('remoteLogOutput', 'line one')
            ->set('remoteLogError', 'old error')
            ->call('clearLogDisplay')
            ->assertSet('remoteLogRaw', '')
            ->assertSet('remoteLogOutput', '')
            ->assertSet('remoteLogError', null);
    }

    public function test_server_logs_includes_per_site_sources_when_sites_exist(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server->fresh()]);

        $sources = $component->instance()->availableLogSources();
        $accessKey = 'site_'.$site->id.'_access';
        $errorKey = 'site_'.$site->id.'_error';

        $this->assertArrayHasKey($accessKey, $sources);
        $this->assertArrayHasKey($errorKey, $sources);
        $this->assertStringContainsString($site->nginxConfigBasename().'-access.log', $sources[$accessKey]['path']);
    }

    public function test_server_logs_regex_filter_matches_lines(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', "match-line\nskip\nmatch-other")
            ->set('logFilterUseRegex', true)
            ->set('logFilter', '^match')
            ->assertSet('logFilteredLines', 2);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', "a\nb")
            ->set('logFilterUseRegex', true)
            ->set('logFilter', '(')
            ->assertSet('logFilterError', __('Invalid regular expression.'));
    }

    public function test_server_show_settings_tab_renders(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
            ->assertSee('Connection & identity')
            ->assertSee('Use the tabs');

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'alerts'])
            ->assertSee('Maintenance window');

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'export'])
            ->assertSee('Download manifest (JSON)');

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'danger'])
            ->assertSee('Danger zone');
    }

    public function test_server_settings_redirects_bare_settings_url_to_connection_tab(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('servers.settings', ['server' => $server]))
            ->assertRedirect(route('servers.settings', ['server' => $server, 'section' => 'connection']));
    }

    public function test_server_settings_unknown_section_returns_404(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('servers.settings', ['server' => $server, 'section' => 'not-a-real-tab']))
            ->assertNotFound();
    }

    public function test_server_manage_workspace_renders(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Manage Me',
        ]);

        $this->actingAs($user)
            ->get(route('servers.manage', ['server' => $server, 'section' => 'overview']))
            ->assertOk()
            ->assertSee('Manage Me');

        Livewire::actingAs($user)
            ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'configuration'])
            ->assertSee('Manage')
            ->assertSee('Configuration files');

        Livewire::actingAs($user)
            ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'services'])
            ->assertSee('Services');
    }

    public function test_server_manage_config_preview_dispatches_background_job_when_enabled(): void
    {
        config(['server_manage.queue_remote_tasks' => true]);

        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'configuration'])
            ->call('previewConfig', 'nginx')
            ->assertSet('manageRemoteTaskId', fn ($id) => is_string($id) && strlen($id) > 0);

        Queue::assertPushed(ServerManageRemoteSshJob::class);

        $this->assertDatabaseHas('server_manage_actions', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'status' => 'queued',
        ]);
    }

    public function test_servers_show_returns_403_for_non_member(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($otherUser->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertForbidden();
    }

    public function test_servers_can_be_destroyed_by_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSites::class, ['server' => $server])
            ->call('openRemoveServerModal')
            ->call('submitRemoveServer')
            ->assertRedirect(route('servers.index'));

        $this->assertModelMissing($server);
    }
}
