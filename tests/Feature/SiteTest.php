<?php

namespace Tests\Feature;

use App\Contracts\DeployEngine;
use App\Enums\ServerProvider;
use App\Enums\SiteType;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Sites\Create as SitesCreate;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Livewire\Sites\Show as SitesShow;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Models\Workspace;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\DockerDeployEngine;
use App\Services\Deploy\KubernetesKubectlExecutor;
use App\Services\Deploy\SiteRuntimeActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_site_settings_runtime_section_shows_php_card_with_current_version_and_installed_versions(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.3',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime'], false));

        $response->assertOk()
            ->assertSee('PHP')
            ->assertSee('Current site version')
            ->assertSee('PHP 8.3')
            ->assertSee('Installed on this server')
            ->assertSee('PHP 8.4')
            ->assertSee('Memory limit')
            ->assertSee('Upload max filesize')
            ->assertSee('Max execution time')
            ->assertSee('OPcache')
            ->assertSee('Composer auth')
            ->assertSee('Extensions');
    }

    public function test_site_settings_deploy_section_shows_docker_runtime_artifacts(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'docker_runtime' => [
                    'compose_yaml' => "services:\n  demo:\n    build:\n      context: .\n",
                    'dockerfile' => "FROM php:8.3-apache\n",
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy'], false));

        $response->assertOk()
            ->assertSee('Deploy')
            ->assertSee('Runtime target')
            ->assertSee('docker compose up -d --build')
            ->assertSee('FROM php:8.3-apache');
    }

    public function test_site_settings_deploy_section_shows_kubernetes_runtime_artifacts(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => [
                    'namespace' => 'orbit-local',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_KUBERNETES_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'kubernetes_web',
                'kubernetes_runtime' => [
                    'namespace' => 'orbit-local',
                    'manifest_yaml' => "apiVersion: apps/v1\nkind: Deployment\nmetadata:\n  namespace: orbit-local\n",
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy'], false));

        $response->assertOk()
            ->assertSee('Deploy')
            ->assertSee('Runtime target')
            ->assertSee('orbit-local')
            ->assertSee('kind: Deployment');
    }

    public function test_site_settings_runtime_section_shows_php_mismatch_state_and_server_php_remediation_link(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.1',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime'], false));

        $response->assertOk()
            ->assertSee('PHP version mismatch')
            ->assertSee('This site references PHP 8.1, but that version is not currently installed on this server.')
            ->assertSee(route('servers.php', $server, false), escape: false)
            ->assertDontSee('value="8.1"', escape: false);
    }

    public function test_site_settings_runtime_section_hides_unsupported_installed_versions(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.5'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.4',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime'], false));

        $response->assertOk()
            ->assertSee('PHP 8.4')
            ->assertDontSee('value="8.5"', escape: false);
    }

    public function test_site_php_settings_can_be_saved_from_site_settings_for_installed_versions_only(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.3',
            'meta' => [
                'php_runtime' => [
                    'memory_limit' => '256M',
                    'upload_max_filesize' => '64M',
                    'max_execution_time' => '60',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->set('php_version', '8.4')
            ->set('php_memory_limit', '512M')
            ->set('php_upload_max_filesize', '128M')
            ->set('php_max_execution_time', '120')
            ->call('savePhpSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'PHP settings saved.');

        $site->refresh();

        $this->assertSame('8.4', $site->php_version);
        $this->assertSame('512M', data_get($site->meta, 'php_runtime.memory_limit'));
        $this->assertSame('128M', data_get($site->meta, 'php_runtime.upload_max_filesize'));
        $this->assertSame('120', (string) data_get($site->meta, 'php_runtime.max_execution_time'));

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site->fresh(), 'section' => 'runtime'])
            ->set('php_version', '8.1')
            ->call('savePhpSettings')
            ->assertHasErrors(['php_version']);
    }

    public function test_php_site_creation_prefills_the_valid_server_new_site_default(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.3',
                ],
                'default_php_version' => '8.3',
                'php_new_site_default_version' => '8.4',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSet('form.type', 'php')
            ->assertSet('form.document_root', '/var/www/app/public')
            ->assertSet('form.repository_path', '/var/www/app')
            ->assertSet('form.php_version', '8.4');
    }

    public function test_site_creation_reconfigures_paths_when_stack_changes(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.3',
                ],
                'default_php_version' => '8.3',
                'php_new_site_default_version' => '8.4',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.primary_hostname', 'app.example.com')
            ->assertSet('form.document_root', '/var/www/app-example-com/public')
            ->assertSet('form.repository_path', '/var/www/app-example-com')
            ->set('form.type', 'node')
            ->assertSet('form.document_root', '/var/www/app-example-com')
            ->assertSet('form.repository_path', '/var/www/app-example-com')
            ->assertSet('form.app_port', 3000)
            ->set('form.type', 'php')
            ->assertSet('form.document_root', '/var/www/app-example-com/public')
            ->assertSet('form.repository_path', '/var/www/app-example-com');
    }

    public function test_site_creation_keeps_auto_paths_hidden_until_customized(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.3',
                ],
                'default_php_version' => '8.3',
                'php_new_site_default_version' => '8.4',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSet('form.customize_paths', false)
            ->set('form.primary_hostname', 'api.example.com')
            ->assertSet('form.document_root', '/var/www/api-example-com/public')
            ->set('form.customize_paths', true)
            ->set('form.document_root', '/srv/custom/public')
            ->set('form.repository_path', '/srv/custom')
            ->set('form.primary_hostname', 'changed.example.com')
            ->assertSet('form.document_root', '/srv/custom/public')
            ->assertSet('form.repository_path', '/srv/custom')
            ->set('form.customize_paths', false)
            ->assertSet('form.document_root', '/var/www/changed-example-com/public')
            ->assertSet('form.repository_path', '/var/www/changed-example-com');
    }

    public function test_php_site_creation_requires_explicit_selection_when_saved_default_is_not_installed(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
                'default_php_version' => '8.4',
                'php_new_site_default_version' => '8.1',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSet('form.type', 'php')
            ->assertSet('form.php_version', '')
            ->set('form.name', 'Example App')
            ->set('form.primary_hostname', 'app.example.com')
            ->set('form.document_root', '/var/www/app/public')
            ->set('form.repository_path', '/var/www/app')
            ->call('store')
            ->assertHasErrors(['form.php_version']);
    }

    public function test_site_creation_queues_async_provisioning_and_redirects_to_site_show(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'webserver' => 'nginx',
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
                'php_new_site_default_version' => '8.4',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'Async Site')
            ->set('form.primary_hostname', 'async.example.com')
            ->set('form.type', 'php')
            ->set('form.php_version', '8.4')
            ->call('store')
            ->assertRedirect();

        $site = Site::query()->where('name', 'Async Site')->firstOrFail();

        $this->assertSame('queued', $site->provisioningState());
        $this->assertSame('nginx', data_get($site->meta, 'provisioning.webserver'));

        Queue::assertPushed(ProvisionSiteJob::class, function (ProvisionSiteJob $job) use ($site): bool {
            return $job->siteId === $site->id && $job->probeAttempt === 0;
        });
    }

    public function test_functions_host_site_creation_uses_runtime_profile_and_artifact_metadata(): void
    {
        Queue::fake();

        $origin = $this->makeGitRepository([
            'package.json' => json_encode([
                'scripts' => [
                    'build' => 'vite build',
                ],
                'dependencies' => [
                    'vite' => '^5.0.0',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => [
                    'api_host' => 'https://faas-nyc1-example.doserverless.co',
                    'namespace' => 'fn-namespace',
                    'access_key' => 'dof_v1_test:secret',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'Functions Site')
            ->set('form.primary_hostname', 'functions.example.com')
            ->set('form.functions_repo_source', 'manual')
            ->set('form.functions_repository_url', $origin)
            ->set('form.functions_repository_branch', 'main')
            ->call('store')
            ->assertRedirect();

        $site = Site::query()->where('name', 'Functions Site')->firstOrFail();

        $this->assertTrue($site->usesFunctionsRuntime());
        $this->assertSame('digitalocean_functions_web', $site->runtimeProfile());
        $this->assertSame($origin, $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame('nodejs:18', data_get($site->meta, 'serverless.runtime'));
        $this->assertSame('index', data_get($site->meta, 'serverless.entrypoint'));
        $this->assertSame('npm install && npm run build', data_get($site->meta, 'serverless.build_command'));
        $this->assertSame('dist', data_get($site->meta, 'serverless.artifact_output_path'));
        $this->assertSame('vite_static', data_get($site->meta, 'serverless.detected_runtime.framework'));
        $this->assertSame(Site::STATUS_PENDING, $site->status);
        $this->assertSame('queued', $site->provisioningState());
    }

    public function test_aws_lambda_host_site_creation_marks_laravel_repo_as_supported(): void
    {
        Queue::fake();

        $origin = $this->makeGitRepository([
            'artisan' => "#!/usr/bin/env php\n",
            'bootstrap/app.php' => "<?php\n",
            'routes/web.php' => "<?php\n",
            'public/index.php' => "<?php\n",
            'composer.json' => json_encode([
                'require' => [
                    'laravel/framework' => '^12.0',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws',
            'meta' => [
                'host_kind' => Server::HOST_KIND_AWS_LAMBDA,
                'aws_lambda' => [
                    'region' => 'us-east-1',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'Laravel Lambda Site')
            ->set('form.primary_hostname', 'laravel-lambda.example.com')
            ->set('form.functions_repo_source', 'manual')
            ->set('form.functions_repository_url', $origin)
            ->set('form.functions_repository_branch', 'main')
            ->call('store')
            ->assertHasNoErrors()
            ->assertRedirect();

        $site = Site::query()->where('name', 'Laravel Lambda Site')->firstOrFail();

        $this->assertTrue($site->usesAwsLambdaRuntime());
        $this->assertSame('aws_lambda_bref_web', $site->runtimeProfile());
        $this->assertSame('provided.al2023', data_get($site->meta, 'serverless.runtime'));
        $this->assertSame('public/index.php', data_get($site->meta, 'serverless.entrypoint'));
        $this->assertSame('laravel', data_get($site->meta, 'serverless.detected_runtime.framework'));
    }

    public function test_docker_host_site_creation_uses_docker_runtime_profile(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'Docker Site')
            ->set('form.primary_hostname', 'docker.example.com')
            ->set('form.type', 'php')
            ->call('store')
            ->assertRedirect();

        $site = Site::query()->where('name', 'Docker Site')->firstOrFail();

        $this->assertTrue($site->usesDockerRuntime());
        $this->assertSame('docker_web', $site->runtimeProfile());
        $this->assertSame('php', $site->type->value);
        $this->assertNull($site->php_version);
        $this->assertSame('queued', $site->provisioningState());
    }

    public function test_kubernetes_host_site_creation_uses_kubernetes_runtime_profile(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => [
                    'namespace' => 'apps',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'Cluster Site')
            ->set('form.primary_hostname', 'cluster.example.com')
            ->set('form.type', 'node')
            ->set('form.app_port', 4000)
            ->call('store')
            ->assertRedirect();

        $site = Site::query()->where('name', 'Cluster Site')->firstOrFail();

        $this->assertTrue($site->usesKubernetesRuntime());
        $this->assertSame('kubernetes_web', $site->runtimeProfile());
        $this->assertSame('apps', data_get($site->meta, 'kubernetes_runtime.namespace'));
        $this->assertSame('queued', $site->provisioningState());
    }

    public function test_functions_host_site_creation_blocks_laravel_repo_on_unsupported_target(): void
    {
        Queue::fake();

        $origin = $this->makeGitRepository([
            'artisan' => "#!/usr/bin/env php\n",
            'bootstrap/app.php' => "<?php\n",
            'routes/web.php' => "<?php\n",
            'public/index.php' => "<?php\n",
            'composer.json' => json_encode([
                'require' => [
                    'laravel/framework' => '^11.0',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => [
                    'api_host' => 'https://faas-nyc1-example.doserverless.co',
                    'namespace' => 'fn-namespace',
                    'access_key' => 'dof_v1_test:secret',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'Laravel Functions Site')
            ->set('form.primary_hostname', 'laravel.example.com')
            ->set('form.functions_repo_source', 'manual')
            ->set('form.functions_repository_url', $origin)
            ->set('form.functions_repository_branch', 'main')
            ->call('store')
            ->assertHasErrors(['form.functions_repository_url']);

        $this->assertNull(Site::query()->where('name', 'Laravel Functions Site')->first());
        Queue::assertNotPushed(ProvisionSiteJob::class);
    }

    public function test_functions_host_site_settings_deploy_hides_server_only_controls(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => [
                    'artifact_path' => '/tmp/functions-site.zip',
                    'entrypoint' => 'index',
                ],
                'provisioning' => [
                    'state' => 'awaiting_first_deploy',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy'], false));

        $response->assertOk()
            ->assertSee('Serverless deploy target')
            ->assertSee('DigitalOcean Functions')
            ->assertDontSee('Install / update Nginx site')
            ->assertDontSee('Issue / renew SSL')
            ->assertDontSee('Push .env to server')
            ->assertDontSee('Generate deploy key');
    }

    public function test_aws_lambda_site_settings_deploy_shows_lambda_details(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws',
            'meta' => [
                'host_kind' => Server::HOST_KIND_AWS_LAMBDA,
                'aws_lambda' => [
                    'region' => 'us-east-1',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => [
                'runtime_profile' => 'aws_lambda_bref_web',
                'serverless' => [
                    'runtime' => 'provided.al2023',
                    'entrypoint' => 'public/index.php',
                    'artifact_path' => '/tmp/laravel-lambda.zip',
                    'function_arn' => 'arn:aws:lambda:us-east-1:123456789012:function:laravel-lambda-site',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy'], false));

        $response->assertOk()
            ->assertSee('Serverless deploy target')
            ->assertSee('AWS Lambda')
            ->assertSee('Function ARN')
            ->assertSee('provided.al2023');
    }

    public function test_functions_host_deploy_uses_digitalocean_functions_engine(): void
    {
        Http::fake([
            'https://faas-nyc1-example.doserverless.co/api/v1/namespaces/*' => Http::response([
                'version' => '7',
            ], 200),
        ]);

        $origin = storage_path('framework/testing/functions-deploy-repo-'.uniqid());
        mkdir($origin, 0777, true);
        (new Process(['git', 'init', '-b', 'main'], $origin))->mustRun();
        file_put_contents($origin.'/README.md', "hello\n");
        (new Process(['git', 'add', '.'], $origin))->mustRun();
        (new Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();
        (new Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => [
                    'api_host' => 'https://faas-nyc1-example.doserverless.co',
                    'namespace' => 'fn-namespace',
                    'access_key' => 'dof_v1_test:secret',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => [
                    'runtime' => 'nodejs:18',
                    'entrypoint' => 'index',
                    'build_command' => 'mkdir -p dist && printf "exports.main = true;\n" > dist/index.js',
                    'artifact_output_path' => 'dist',
                ],
            ],
            'git_repository_url' => $origin,
            'git_branch' => 'main',
        ]);

        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

        $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
        $site->refresh();

        $this->assertSame(SiteDeployment::STATUS_SUCCESS, $deployment->status);
        $this->assertSame('7', $deployment->git_sha);
        $this->assertSame(Site::STATUS_FUNCTIONS_ACTIVE, $site->status);
        $this->assertSame('7', data_get($site->meta, 'serverless.last_revision_id'));
        $this->assertNotNull(data_get($site->meta, 'serverless.artifact_path'));
        $this->assertStringContainsString('DigitalOcean Functions deploy completed.', (string) $deployment->log_output);
    }

    public function test_aws_lambda_host_deploy_uses_aws_lambda_engine(): void
    {
        $origin = $this->makeGitRepository([
            'artisan' => "#!/usr/bin/env php\n",
            'bootstrap/app.php' => "<?php\n",
            'routes/web.php' => "<?php\n",
            'public/index.php' => "<?php\n",
            'composer.json' => json_encode([
                'require' => [
                    'laravel/framework' => '^12.0',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws',
            'meta' => [
                'host_kind' => Server::HOST_KIND_AWS_LAMBDA,
                'aws_lambda' => [
                    'region' => 'us-east-1',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'aws_lambda_bref_web',
                'serverless' => [
                    'runtime' => 'provided.al2023',
                    'entrypoint' => 'public/index.php',
                    'build_command' => 'mkdir -p dist && printf "exports.main = true;\n" > dist/index.js',
                    'artifact_output_path' => 'dist',
                    'function_name' => 'laravel-lambda-site',
                ],
            ],
            'git_repository_url' => $origin,
            'git_branch' => 'main',
        ]);

        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

        $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
        $site->refresh();

        $this->assertSame(SiteDeployment::STATUS_SUCCESS, $deployment->status);
        $this->assertSame('aws-stub-revision-1', $deployment->git_sha);
        $this->assertSame(Site::STATUS_FUNCTIONS_ACTIVE, $site->status);
        $this->assertSame(Server::HOST_KIND_AWS_LAMBDA, data_get($site->meta, 'serverless.target'));
        $this->assertSame('aws-stub-revision-1', data_get($site->meta, 'serverless.last_revision_id'));
        $this->assertNotNull(data_get($site->meta, 'serverless.artifact_path'));
        $this->assertStringContainsString('AWS Lambda deploy completed.', (string) $deployment->log_output);
    }

    public function test_functions_configured_state_is_ready_for_workspace_but_not_traffic(): void
    {
        $site = new Site([
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
            ],
        ]);

        $this->assertTrue($site->isReadyForWorkspace());
        $this->assertFalse($site->isReadyForTraffic());
        $this->assertSame('functions configured', $site->statusLabel());
    }

    public function test_docker_host_site_provisioning_prepares_runtime_until_first_deploy(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'runtime_profile' => 'docker_web',
            ],
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'docker.example.test',
            'is_primary' => true,
        ]);

        ProvisionSiteJob::dispatchSync($site->id);

        $site->refresh();

        $this->assertSame(Site::STATUS_DOCKER_CONFIGURED, $site->status);
        $this->assertSame('awaiting_first_deploy', $site->provisioningState());
        $this->assertNotEmpty(data_get($site->meta, 'docker_runtime.compose_yaml'));
    }

    public function test_docker_host_deploy_uses_docker_engine(): void
    {
        app()->instance(DockerDeployEngine::class, new class implements DeployEngine
        {
            public function run(DeployContext $context): array
            {
                $site = $context->site();
                $meta = is_array($site->meta) ? $site->meta : [];
                $dockerRuntime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];
                $meta['docker_runtime'] = array_merge($dockerRuntime, [
                    'compose_yaml' => "services:\n  app:\n    image: example\n",
                    'dockerfile' => "FROM php:8.3-cli\n",
                    'last_deployed_at' => now()->toIso8601String(),
                ]);
                $site->forceFill(['meta' => $meta])->save();

                return [
                    'output' => 'Docker deploy prepared.',
                    'sha' => null,
                ];
            }
        });

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'docker_web',
            ],
        ]);

        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

        $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
        $site->refresh();

        $this->assertSame(SiteDeployment::STATUS_SUCCESS, $deployment->status);
        $this->assertSame(Site::STATUS_DOCKER_CONFIGURED, $site->status);
        $this->assertStringContainsString('Docker deploy prepared.', (string) $deployment->log_output);
        $this->assertNotEmpty(data_get($site->meta, 'docker_runtime.compose_yaml'));
    }

    public function test_kubernetes_host_deploy_uses_kubernetes_engine(): void
    {
        app()->instance(KubernetesKubectlExecutor::class, new class extends KubernetesKubectlExecutor
        {
            public function deploy(
                string $manifest,
                string $namespace,
                string $deploymentName,
                ?string $kubeconfigPath = null,
                ?string $context = null,
            ): array {
                return [
                    'output' => "namespace/{$namespace} unchanged\ndeployment.apps/{$deploymentName} configured\ndeployment \"{$deploymentName}\" successfully rolled out",
                    'revision' => '3',
                    'context' => $context ?? 'orbit-local',
                ];
            }
        });

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => [
                    'namespace' => 'dply-tests',
                    'cluster_name' => 'local-orbit',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_KUBERNETES_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'kubernetes_web',
            ],
        ]);

        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

        $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
        $site->refresh();

        $this->assertSame(SiteDeployment::STATUS_SUCCESS, $deployment->status);
        $this->assertSame('3', $deployment->git_sha);
        $this->assertSame(Site::STATUS_KUBERNETES_ACTIVE, $site->status);
        $this->assertStringContainsString('Kubernetes deploy applied.', (string) $deployment->log_output);
        $this->assertSame('dply-tests', data_get($site->meta, 'kubernetes_runtime.namespace'));
        $this->assertNotEmpty(data_get($site->meta, 'kubernetes_runtime.manifest_yaml'));
        $this->assertSame(Str::slug($site->slug ?: $site->name), data_get($site->meta, 'kubernetes_runtime.deployment_name'));
        $this->assertSame('3', data_get($site->meta, 'kubernetes_runtime.last_revision_id'));
        $this->assertSame('orbit-local', data_get($site->meta, 'kubernetes_runtime.kubectl_context'));
    }

    /**
     * @param  array<string, string>  $files
     */
    private function makeGitRepository(array $files): string
    {
        $origin = storage_path('framework/testing/functions-site-repo-'.uniqid());
        mkdir($origin, 0777, true);

        foreach ($files as $path => $contents) {
            $absolutePath = $origin.'/'.$path;
            $directory = dirname($absolutePath);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($absolutePath, $contents);
        }

        (new Process(['git', 'init', '-b', 'main'], $origin))->mustRun();
        (new Process(['git', 'add', '.'], $origin))->mustRun();
        (new Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();
        (new Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();

        return $origin;
    }

    public function test_site_show_renders_provisioning_status_card(): void
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
            'status' => Site::STATUS_PENDING,
            'meta' => [
                'testing_hostname' => [
                    'status' => 'ready',
                    'hostname' => 'preview-app.dply.cc',
                ],
                'provisioning' => [
                    'state' => 'waiting_for_http',
                    'webserver' => 'nginx',
                ],
            ],
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview-app.dply.cc',
            'is_primary' => false,
            'www_redirect' => false,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Installing your site')
            ->assertSee('Checking reachability')
            ->assertSee('preview-app.dply.cc');
    }

    public function test_site_settings_route_redirects_to_site_show_general_section(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site], false));

        $response->assertRedirect(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'], false));
    }

    public function test_site_show_defaults_to_general_site_workspace(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Site workspace')
            ->assertSee('General')
            ->assertSee('Site project settings')
            ->assertSee('Save project settings')
            ->assertSee('Deployment foundation')
            ->assertSee('Deployment log')
            ->assertSee('Site notes');
    }

    public function test_site_show_surfaces_deployment_foundation_preflight_and_resource_state(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
            'git_repository_url' => null,
            'env_file_content' => "APP_KEY=base64:test-key\nAPP_NAME=Dply Demo\n",
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
                ],
                'deployment_foundation' => [
                    'applied_revisions' => [
                        'runtime' => 'old-runtime-revision',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Launch preflight')
            ->assertSee('A repository URL is required for this runtime target.')
            ->assertSee('Attached resources')
            ->assertSee('Publication')
            ->assertSee('Deployment foundation')
            ->assertSee('Shared secrets &amp; config', false)
            ->assertSee('1 secret')
            ->assertSee('1 config value')
            ->assertSee('APP_KEY')
            ->assertSee('Redacted')
            ->assertSee('APP_NAME')
            ->assertSee('Dply Demo')
            ->assertSee('Injected into the managed Docker runtime inputs Dply builds for this site.')
            ->assertSee('Current revision')
            ->assertSee('Last applied revision')
            ->assertSee('Detected');
    }

    public function test_site_environment_section_uses_shared_inventory_for_serverless_sites(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            'env_file_content' => "APP_KEY=base64:serverless-key\nAPP_NAME=Functions Demo\n",
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'runtime_target' => [
                    'family' => 'digitalocean_functions',
                    'platform' => 'digitalocean',
                    'provider' => 'digitalocean',
                    'mode' => 'serverless',
                    'status' => 'configured',
                    'logs' => [],
                ],
                'serverless' => [
                    'runtime' => 'php-8.3',
                    'entrypoint' => 'index',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
            'section' => 'environment',
        ], false));

        $response->assertOk()
            ->assertSee('Shared environment inventory')
            ->assertSee('Final inventory')
            ->assertSee('2')
            ->assertSee('Runtime delivery')
            ->assertSee('Injected into the provider runtime environment payload during publish.')
            ->assertSee('Shared inventory preview')
            ->assertSee('APP_KEY')
            ->assertSee('Redacted')
            ->assertSee('APP_NAME')
            ->assertSee('Functions Demo')
            ->assertDontSee('Push .env to server');
    }

    public function test_site_settings_legacy_routing_section_redirects_to_routing_tab(): void
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

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site, 'section' => 'aliases'], false));

        $response->assertRedirect(route('sites.show', [
            $server,
            $site,
            'section' => 'routing',
            'tab' => 'aliases',
        ], false));
    }

    public function test_site_settings_deploy_section_shows_no_downtime_scripts_hooks_variables_and_log_links(): void
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
            'deploy_strategy' => 'atomic',
            'releases_to_keep' => 8,
            'git_repository_url' => 'git@github.com:org/repo.git',
            'git_branch' => 'main',
            'post_deploy_command' => 'php artisan optimize',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteDeployStep::query()->create([
            'site_id' => $site->id,
            'sort_order' => 1,
            'step_type' => SiteDeployStep::TYPE_NPM_CI,
            'timeout_seconds' => 900,
        ]);
        SiteDeployHook::query()->create([
            'site_id' => $site->id,
            'phase' => SiteDeployHook::PHASE_AFTER_CLONE,
            'script' => 'php artisan migrate --force',
            'sort_order' => 1,
            'timeout_seconds' => 900,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy'], false));

        $response->assertOk()
            ->assertSee('Deploy')
            ->assertSee('No downtime')
            ->assertSee('Pre-deploy script')
            ->assertSee('Main deploy script')
            ->assertSee('Post-deploy script')
            ->assertSee('Deploy hooks')
            ->assertSee('Deploy script variables')
            ->assertSee('{SITE_DOMAIN}')
            ->assertSee('{BRANCH}')
            ->assertSee(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs'], false), escape: false)
            ->assertSee(route('servers.logs', $server, false), escape: false);
    }

    public function test_site_settings_deploy_section_can_save_repository_and_strategy_settings(): void
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
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 3,
            'deployment_environment' => 'production',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'deploy'])
            ->set('git_repository_url', 'git@github.com:acme/example.git')
            ->set('git_branch', 'release')
            ->set('post_deploy_command', 'php artisan optimize')
            ->call('saveGit')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Git settings saved.')
            ->set('deploy_strategy', 'atomic')
            ->set('releases_to_keep', 8)
            ->set('deployment_environment', 'staging')
            ->set('octane_port', '8080')
            ->set('php_fpm_user', 'deploy')
            ->set('laravel_scheduler', true)
            ->set('restart_supervisor_programs_after_deploy', true)
            ->set('nginx_extra_raw', 'location /health { return 200; }')
            ->call('saveDeploymentSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Deployment / Nginx settings saved. Re-install Nginx if you changed redirects, Octane, or extra config. Re-sync server crontab for Laravel scheduler. When “Restart Supervisor after deploy” is on, Dply restarts programs for this site (and server-wide programs) after a successful deploy.');

        $site->refresh();

        $this->assertSame('git@github.com:acme/example.git', $site->git_repository_url);
        $this->assertSame('release', $site->git_branch);
        $this->assertSame('php artisan optimize', $site->post_deploy_command);
        $this->assertSame('atomic', $site->deploy_strategy);
        $this->assertSame(8, $site->releases_to_keep);
        $this->assertSame('staging', $site->deployment_environment);
        $this->assertSame(8080, $site->octane_port);
        $this->assertSame('deploy', $site->php_fpm_user);
        $this->assertTrue($site->laravel_scheduler);
        $this->assertTrue($site->restart_supervisor_programs_after_deploy);
        $this->assertSame('location /health { return 200; }', $site->nginx_extra_raw);
    }

    public function test_site_settings_general_section_uses_certificate_summary_for_ssl_status(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
            'ssl_status' => Site::SSL_NONE,
        ]);
        SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['app.example.com'],
            'status' => SiteCertificate::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'], false));

        $response->assertOk()
            ->assertSee('SSL')
            ->assertSee('active');
    }

    public function test_site_settings_general_section_shows_project_context_links(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'Customer Platform',
        ]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'workspace_id' => $workspace->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'], false));

        $response->assertOk()
            ->assertSee('Current project')
            ->assertSee('Customer Platform')
            ->assertSee('Open project resources')
            ->assertSee('Open project operations')
            ->assertSee('Open project delivery');
    }

    public function test_site_settings_general_section_shows_site_details_and_notes(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.4',
            'meta' => [
                'notes' => 'Important operator notes.',
                'disk_usage' => [
                    'bytes' => 174325760,
                ],
            ],
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'], false));

        $response->assertOk()
            ->assertSee('Site details')
            ->assertSee('Site ID')
            ->assertSee('Site notes')
            ->assertSee('Important operator notes.')
            ->assertDontSee('Save PHP settings');
    }

    public function test_site_settings_component_rejects_unknown_section(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'nope'])
            ->assertStatus(404);
    }

    public function test_site_show_links_to_dedicated_settings_workspace_and_omits_settings_forms(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.3',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'], false), escape: false)
            ->assertSee('Site settings')
            ->assertSee('Deployment log')
            ->assertDontSee('Save PHP settings')
            ->assertDontSee('Deploy webhook')
            ->assertDontSee('Environment (.env)');
    }

    public function test_site_show_displays_aws_lambda_runtime_target_details(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws',
            'meta' => [
                'host_kind' => Server::HOST_KIND_AWS_LAMBDA,
                'aws_lambda' => [
                    'region' => 'us-east-1',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => [
                'runtime_profile' => 'aws_lambda_bref_web',
                'serverless' => [
                    'runtime' => 'provided.al2023',
                    'entrypoint' => 'public/index.php',
                    'last_revision_id' => 'aws-stub-revision-1',
                    'artifact_path' => '/tmp/laravel-lambda.zip',
                    'function_arn' => 'arn:aws:lambda:us-east-1:123456789012:function:laravel-lambda-site',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Runtime target')
            ->assertSee('AWS Lambda')
            ->assertSee('aws-stub-revision-1')
            ->assertSee('Function ARN')
            ->assertSee('provided.al2023');
    }

    public function test_site_show_displays_docker_runtime_target_summary(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_ACTIVE,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'running',
                    'publication' => [
                        'hostname' => 'laravel.repo.orb.local',
                        'url' => 'http://laravel.repo.orb.local',
                        'container_ip' => '192.168.107.2',
                    ],
                ],
                'docker_runtime' => [
                    'compose_yaml' => "services:\n  app:\n    image: example\n",
                    'dockerfile' => "FROM php:8.3-cli\n",
                    'runtime_details' => [
                        'containers' => [[
                            'name' => 'laravel.repo',
                            'service' => 'app',
                            'orb_hostname' => 'laravel.repo.orb.local',
                            'ipv4' => '192.168.107.2',
                        ]],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->assertSee('Runtime target')
            ->assertSee('Last generated compose file')
            ->assertSee('Managed Dockerfile')
            ->assertSee('Docker discovery')
            ->assertSee('laravel.repo.orb.local')
            ->assertSee('192.168.107.2')
            ->assertSee('laravel.repo');
    }

    public function test_site_show_displays_kubernetes_runtime_target_summary(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => [
                    'namespace' => 'orbit-local',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_KUBERNETES_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'kubernetes_web',
                'kubernetes_runtime' => [
                    'namespace' => 'orbit-local',
                    'manifest_yaml' => "apiVersion: apps/v1\nkind: Deployment\nmetadata:\n  namespace: orbit-local\n",
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Runtime target')
            ->assertSee('Kubernetes cluster')
            ->assertSee('Namespace')
            ->assertSee('orbit-local')
            ->assertSee('Manifest');
    }

    public function test_runtime_target_model_maps_local_and_cloud_container_families(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $localServer = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::Custom,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $localSite = Site::factory()->create([
            'server_id' => $localServer->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'runtime_profile' => 'docker_web',
            ],
        ]);

        $digitalOceanServer = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
            ],
        ]);
        $digitalOceanSite = Site::factory()->create([
            'server_id' => $digitalOceanServer->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'runtime_profile' => 'kubernetes_web',
            ],
        ]);

        $this->assertSame('local_orbstack_docker', $localSite->runtimeTargetFamily());
        $this->assertSame('local', $localSite->runtimeTargetPlatform());
        $this->assertTrue($localSite->usesLocalDockerHostRuntime());

        $this->assertSame('digitalocean_kubernetes', $digitalOceanSite->runtimeTargetFamily());
        $this->assertSame('digitalocean', $digitalOceanSite->runtimeTargetPlatform());
        $this->assertSame('kubernetes', $digitalOceanSite->runtimeTargetMode());
    }

    public function test_site_show_exposes_orbstack_runtime_controls_and_records_runtime_actions(): void
    {
        app()->instance(SiteRuntimeActionExecutor::class, new class extends SiteRuntimeActionExecutor
        {
            public function __construct() {}

            public function run(Site $site, string $action): array
            {
                return [
                    'status' => 'running',
                    'output' => 'Stub runtime action output for '.$action,
                ];
            }
        });

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::Custom,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_ACTIVE,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'running',
                    'logs' => [],
                ],
                'docker_runtime' => [
                    'workspace_path' => storage_path('app/local-runtimes/'.$server->id),
                    'compose_yaml' => "services:\n  app:\n    image: example\n",
                    'dockerfile' => "FROM php:8.3-cli\n",
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->assertSee('Runtime controls')
            ->assertSee('Rebuild')
            ->assertSee('Refresh Docker details')
            ->assertSee('Destroy')
            ->call('runRuntimeAction', 'status')
            ->assertSet('flash_success', 'Runtime status refreshed.');

        $site->refresh();

        $this->assertSame('status', data_get($site->meta, 'runtime_target.last_operation'));
        $this->assertStringContainsString('Stub runtime action output', (string) data_get($site->meta, 'runtime_target.last_operation_output'));
        $this->assertNotEmpty(data_get($site->meta, 'runtime_target.logs'));
    }

    public function test_site_show_surfaces_runtime_error_console_from_error_diagnostics(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::Custom,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_ACTIVE,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'error_diagnostics',
                    'logs' => [[
                        'action' => 'errors',
                        'status' => 'error_diagnostics',
                        'output' => "Runtime error diagnostics refreshed.\n\n--- laravel.log tail ---\nproduction.ERROR: Database file does not exist.",
                        'ran_at' => now()->toIso8601String(),
                    ]],
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->assertSee('Runtime errors')
            ->assertSee('Database file does not exist');
    }

    public function test_site_settings_runtime_section_shows_docker_management_and_discovery(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::Custom,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_ACTIVE,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'running',
                    'publication' => [
                        'hostname' => 'laravel.repo.orb.local',
                        'container_ip' => '192.168.107.2',
                        'container_name' => 'laravel.repo',
                        'docker_service' => 'app',
                    ],
                    'logs' => [],
                ],
                'docker_runtime' => [
                    'compose_yaml' => "services:\n  app:\n    image: example\n",
                    'dockerfile' => "FROM php:8.3-cli\n",
                    'runtime_details' => [
                        'collected_at' => now()->toIso8601String(),
                        'containers' => [[
                            'name' => 'laravel.repo',
                            'service' => 'app',
                            'orb_hostname' => 'laravel.repo.orb.local',
                            'ipv4' => '192.168.107.2',
                            'state' => 'running',
                        ]],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime'], false));

        $response->assertOk()
            ->assertSee('Container app workspace')
            ->assertSee('Back to apps')
            ->assertSee('Overview')
            ->assertSee('Deployments')
            ->assertSee('Networking')
            ->assertSee('Automation')
            ->assertDontSee('Certificates')
            ->assertSee('Docker discovery')
            ->assertSee('Runtime management')
            ->assertSee('Errors')
            ->assertSee('Refresh Docker details')
            ->assertSee('laravel.repo.orb.local')
            ->assertSee('192.168.107.2')
            ->assertSee('laravel.repo');
    }

    public function test_site_settings_general_section_uses_app_language_for_cloud_runtime_paths(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_CONFIGURED,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'digitalocean_docker',
                    'platform' => 'digitalocean',
                    'provider' => 'digitalocean',
                    'mode' => 'docker',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'], false));

        $response->assertOk()
            ->assertSee('Cloud app workspace')
            ->assertSee('Primary hostname')
            ->assertSee('Overview')
            ->assertSee('Networking')
            ->assertSee('App project settings')
            ->assertSee('App details');
    }

    public function test_refresh_docker_details_persists_discovered_runtime_metadata(): void
    {
        app()->instance(SiteRuntimeActionExecutor::class, new class extends SiteRuntimeActionExecutor
        {
            public function __construct() {}

            public function run(Site $site, string $action): array
            {
                return [
                    'status' => 'running',
                    'output' => 'Docker details refreshed.',
                    'publication' => [
                        'hostname' => 'laravel.repo.orb.local',
                        'url' => 'http://laravel.repo.orb.local',
                        'container_ip' => '192.168.107.2',
                    ],
                    'runtime_details' => [
                        'containers' => [[
                            'name' => 'laravel.repo',
                            'service' => 'app',
                            'orb_hostname' => 'laravel.repo.orb.local',
                            'ipv4' => '192.168.107.2',
                        ]],
                    ],
                ];
            }
        });

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::Custom,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_ACTIVE,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'running',
                    'logs' => [],
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('runRuntimeAction', 'inspect')
            ->assertSet('flash_success', 'Docker details refreshed.');

        $site->refresh();

        $this->assertSame('inspect', data_get($site->meta, 'runtime_target.last_operation'));
        $this->assertSame('laravel.repo.orb.local', data_get($site->meta, 'runtime_target.publication.hostname'));
        $this->assertSame('192.168.107.2', data_get($site->meta, 'runtime_target.publication.container_ip'));
        $this->assertSame('laravel.repo.orb.local', data_get($site->meta, 'docker_runtime.runtime_details.containers.0.orb_hostname'));
    }

    public function test_site_show_records_failed_orbstack_runtime_actions_with_debug_output(): void
    {
        app()->instance(SiteRuntimeActionExecutor::class, new class extends SiteRuntimeActionExecutor
        {
            public function __construct() {}

            public function run(Site $site, string $action): array
            {
                throw new \RuntimeException("docker compose failed\n\nWorking directory: /tmp/demo\nCommand: docker compose up -d");
            }
        });

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => ServerProvider::Custom,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_DOCKER_ACTIVE,
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'running',
                    'logs' => [],
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('runRuntimeAction', 'rebuild')
            ->assertSet('flash_error', "docker compose failed\n\nWorking directory: /tmp/demo\nCommand: docker compose up -d");

        $site->refresh();

        $this->assertSame('failed', data_get($site->meta, 'runtime_target.last_operation_status'));
        $this->assertSame('rebuild', data_get($site->meta, 'runtime_target.last_operation'));
        $this->assertStringContainsString('Working directory: /tmp/demo', (string) data_get($site->meta, 'runtime_target.last_operation_output'));
        $this->assertNotEmpty(data_get($site->meta, 'runtime_target.logs'));
        $this->assertSame('failed', data_get($site->meta, 'runtime_target.logs.0.status'));
    }

    public function test_site_show_displays_preview_and_certificate_summary(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
            'ssl_status' => Site::SSL_NONE,
        ]);
        SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview-app.dply.cc',
            'label' => 'Managed preview',
            'dns_status' => 'ready',
            'ssl_status' => 'pending',
            'is_primary' => true,
            'auto_ssl' => true,
            'https_redirect' => true,
            'managed_by_dply' => true,
        ]);
        SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_PREVIEW,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['preview-app.dply.cc'],
            'status' => SiteCertificate::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Preview & SSL')
            ->assertSee('preview-app.dply.cc')
            ->assertSee('ready')
            ->assertSee('Letsencrypt')
            ->assertSee('Preview')
            ->assertSee('active');
    }

    public function test_site_show_displays_certificate_retry_affordance_for_failed_certificate(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['app.example.com'],
            'status' => SiteCertificate::STATUS_FAILED,
            'last_output' => 'DNS validation failed for app.example.com',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Latest certificate output')
            ->assertSee('DNS validation failed for app.example.com')
            ->assertSee('Retry certificate')
            ->assertSee('Open certificate settings');
    }

    public function test_site_show_can_retry_a_failed_certificate(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        $certificate = SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_CSR,
            'challenge_type' => SiteCertificate::CHALLENGE_MANUAL,
            'domains_json' => ['app.example.com'],
            'status' => SiteCertificate::STATUS_FAILED,
            'last_output' => 'Initial failure',
        ]);

        $this->mock(CertificateRequestService::class, function ($mock) use ($certificate): void {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(fn (SiteCertificate $passed): bool => $passed->is($certificate))
                ->andReturnUsing(function (SiteCertificate $passed): SiteCertificate {
                    $passed->forceFill([
                        'status' => SiteCertificate::STATUS_ISSUED,
                        'last_output' => 'CSR regenerated successfully',
                    ])->save();

                    return $passed->fresh();
                });
        });

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('retryCertificate', $certificate->id)
            ->assertSet('flash_success', 'Certificate retry finished.');

        $certificate->refresh();

        $this->assertSame(SiteCertificate::STATUS_ISSUED, $certificate->status);
        $this->assertSame('CSR regenerated successfully', $certificate->last_output);
    }

    public function test_sites_index_shows_provisioning_badge_and_visit_link_only_when_ready(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $pending = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Pending Site',
            'status' => Site::STATUS_PENDING,
            'meta' => [
                'provisioning' => [
                    'state' => 'waiting_for_http',
                ],
            ],
        ]);
        SiteDomain::query()->create([
            'site_id' => $pending->id,
            'hostname' => 'pending.example.com',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $ready = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Ready Site',
            'status' => Site::STATUS_NGINX_ACTIVE,
            'meta' => [
                'provisioning' => [
                    'state' => 'ready',
                    'ready_hostname' => 'ready.dply.cc',
                ],
            ],
        ]);
        SiteDomain::query()->create([
            'site_id' => $ready->id,
            'hostname' => 'ready.example.com',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $response = $this->actingAs($user)->get(route('sites.index', absolute: false));

        $response->assertOk()
            ->assertSee('Pending Site')
            ->assertSee('Provisioning')
            ->assertSee('Ready Site')
            ->assertSee('http://ready.dply.cc')
            ->assertDontSee('http://pending.example.com');
    }

    public function test_release_deploy_lock_uses_confirmation_modal(): void
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

        cache()->put('site-deploy-active:'.$site->id, ['deployment_id' => 'abc'], 60);
        cache()->lock('site-deploy:'.$site->id, 60)->get();

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call(
                'openConfirmActionModal',
                'releaseDeployLock',
                [],
                'Clear deploy lock',
                'Force-clear the deploy lock? Only if no worker is actually deploying.',
                'Clear lock',
                true
            )
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'releaseDeployLock')
            ->call('confirmActionModal')
            ->assertSet('flash_success', 'Deploy lock cleared. If a worker is still running, stop it on the queue host; otherwise you can deploy again.');

        $this->assertNull(cache()->get('site-deploy-active:'.$site->id));
    }

    public function test_site_settings_domains_section_can_remove_domain_through_modal_state(): void
    {
        Bus::fake();

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
        $domain = SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'www.example.test',
            'is_primary' => false,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->call(
                'openConfirmActionModal',
                'removeDomain',
                [$domain->id],
                'Remove domain',
                'Remove this domain?',
                'Remove domain',
                true
            )
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'removeDomain')
            ->call('confirmActionModal');

        $this->assertDatabaseMissing('site_domains', ['id' => $domain->id]);
        Bus::assertDispatchedSync(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
    }

    public function test_site_settings_aliases_section_can_add_alias(): void
    {
        Bus::fake();

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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'aliases')
            ->set('new_alias_hostname', 'www.example.com')
            ->set('new_alias_label', 'Marketing alias')
            ->call('addAlias')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Alias added. Webserver config reloaded.');

        $this->assertDatabaseHas('site_domain_aliases', [
            'site_id' => $site->id,
            'hostname' => 'www.example.com',
            'label' => 'Marketing alias',
        ]);
        Bus::assertDispatchedSync(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
    }

    public function test_site_settings_tenants_section_can_add_tenant_domain(): void
    {
        Bus::fake();

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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'tenants')
            ->set('new_tenant_hostname', 'acme.example.com')
            ->set('new_tenant_key', 'acme')
            ->set('new_tenant_label', 'Acme')
            ->set('new_tenant_notes', 'App resolver uses the hostname.')
            ->call('addTenantDomain')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Tenant domain added. Webserver config reloaded.');

        $this->assertDatabaseHas('site_tenant_domains', [
            'site_id' => $site->id,
            'hostname' => 'acme.example.com',
            'tenant_key' => 'acme',
            'label' => 'Acme',
        ]);
        Bus::assertDispatchedSync(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
    }

    public function test_site_settings_redirects_section_renders_separately(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'redirects'], false));

        $response->assertOk()
            ->assertSee('Redirects')
            ->assertSee('Add redirect')
            ->assertSee('Apply webserver config now');
    }

    public function test_site_settings_webhooks_section_can_save_ip_allow_list(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'webhooks'])
            ->set('webhook_allowed_ips_text', "203.0.113.10\n192.0.2.0/24")
            ->call('saveWebhookSecurity')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Webhook IP allow list saved. Leave empty to allow any source (signature still required).');

        $site->refresh();

        $this->assertSame(['203.0.113.10', '192.0.2.0/24'], $site->webhook_allowed_ips);
    }

    public function test_site_settings_logs_section_renders_site_deployments_and_webhook_deliveries(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'git_sha' => 'abc123',
            'log_output' => 'Deploy completed successfully.',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);
        WebhookDeliveryLog::query()->create([
            'site_id' => $site->id,
            'request_ip' => '203.0.113.10',
            'http_status' => 202,
            'outcome' => WebhookDeliveryLog::OUTCOME_ACCEPTED,
            'detail' => 'Accepted deploy webhook.',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs'], false));

        $response->assertOk()
            ->assertSee('Logs')
            ->assertSee('Recent deploys')
            ->assertSee('Deploy completed successfully.')
            ->assertSee('Webhook delivery log')
            ->assertSee('Accepted deploy webhook.')
            ->assertSee(route('servers.logs', $server, false), escape: false);
    }

    public function test_site_settings_general_section_can_save_primary_domain_and_web_directory(): void
    {
        Bus::fake();

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
            'document_root' => '/var/www/old/public',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        $domain = SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'old.example.com',
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('settings_primary_domain', 'new.example.com')
            ->set('settings_document_root', '/srv/new/public')
            ->call('saveGeneralSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Site settings saved. Webserver config reloaded.');

        $site->refresh();
        $domain->refresh();

        $this->assertSame('/srv/new/public', $site->document_root);
        $this->assertSame('new.example.com', $domain->hostname);
        Bus::assertDispatchedSync(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
    }

    public function test_site_project_settings_can_assign_workspace(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'Assigned Project',
        ]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'workspace_id' => null,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('project_workspace_id', $workspace->id)
            ->call('saveProjectSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Project settings saved.');

        $site->refresh();

        $this->assertSame($workspace->id, $site->workspace_id);
    }

    public function test_site_project_settings_reject_workspace_from_another_organization(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();
        $otherOrg->users()->attach($otherUser->id, ['role' => 'owner']);

        $foreignWorkspace = Workspace::factory()->create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
        ]);
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

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('project_workspace_id', $foreignWorkspace->id)
            ->call('saveProjectSettings')
            ->assertForbidden();

        $site->refresh();

        $this->assertNotSame($foreignWorkspace->id, $site->workspace_id);
    }

    public function test_site_settings_runtime_section_can_save_php_version(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.3',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->set('php_version', '8.4')
            ->call('savePhpSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'PHP settings saved.');

        $site->refresh();

        $this->assertSame('8.4', $site->php_version);
    }

    public function test_site_settings_runtime_section_hides_php_workspace_for_non_php_sites(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Node,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime'], false));

        $response->assertOk()
            ->assertDontSee('Save PHP settings')
            ->assertDontSee('Current site version')
            ->assertDontSee('Deploy strategy')
            ->assertSee('Runtime');
    }

    public function test_site_settings_general_section_can_save_site_notes(): void
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
            'meta' => [],
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('site_notes', 'Remember the vendor firewall allow list.')
            ->call('saveSiteNotes')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Site notes saved.');

        $site->refresh();

        $this->assertSame('Remember the vendor firewall allow list.', data_get($site->meta, 'notes'));
    }

    public function test_site_settings_preview_section_can_save_primary_preview_domain(): void
    {
        Bus::fake();

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
            'meta' => [
                'testing_hostname' => [
                    'hostname' => 'legacy-preview.dply.cc',
                    'status' => 'pending',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'preview')
            ->set('preview_primary_hostname', 'preview-new.dply.cc')
            ->set('preview_label', 'Managed preview')
            ->set('preview_auto_ssl', true)
            ->set('preview_https_redirect', true)
            ->call('savePreviewSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Preview settings saved. Webserver config reloaded.');

        $site->refresh();
        $previewDomain = SitePreviewDomain::query()->where('site_id', $site->id)->first();

        $this->assertNotNull($previewDomain);
        $this->assertSame('preview-new.dply.cc', $previewDomain->hostname);
        $this->assertTrue($previewDomain->is_primary);
        $this->assertSame('preview-new.dply.cc', $site->testingHostname());
        Bus::assertDispatchedSync(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
    }

    public function test_site_settings_domains_section_shows_quick_ssl_action_only_for_uncovered_domains(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.com',
            'is_primary' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'www.example.com',
            'is_primary' => false,
        ]);
        SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['app.example.com'],
            'status' => SiteCertificate::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains'], false));

        $response->assertOk()
            ->assertSee('SSL configured')
            ->assertSee('SSL missing')
            ->assertSee("openQuickDomainSslModal('www.example.com')", escape: false)
            ->assertDontSee("openQuickDomainSslModal('app.example.com')", escape: false);
    }

    public function test_site_settings_domains_section_can_quick_add_letsencrypt_ssl(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.com',
            'is_primary' => true,
        ]);

        $this->mock(CertificateRequestService::class, function ($mock) use ($site): void {
            $mock->shouldReceive('create')
                ->once()
                ->withArgs(function (array $attributes) use ($site): bool {
                    return $attributes['site_id'] === $site->id
                        && $attributes['scope_type'] === SiteCertificate::SCOPE_CUSTOMER
                        && $attributes['provider_type'] === SiteCertificate::PROVIDER_LETSENCRYPT
                        && $attributes['challenge_type'] === SiteCertificate::CHALLENGE_HTTP
                        && $attributes['domains_json'] === ['app.example.com'];
                })
                ->andReturnUsing(function (array $attributes): SiteCertificate {
                    return SiteCertificate::query()->create($attributes);
                });

            $mock->shouldReceive('execute')
                ->once()
                ->andReturnUsing(function (SiteCertificate $certificate): SiteCertificate {
                    $certificate->forceFill([
                        'status' => SiteCertificate::STATUS_ACTIVE,
                        'last_output' => 'Issued successfully',
                    ])->save();

                    return $certificate->fresh();
                });
        });

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->call('openQuickDomainSslModal', 'app.example.com')
            ->assertSet('quick_ssl_domain_hostname', 'app.example.com')
            ->set('quick_ssl_provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->call('quickAddDomainSsl')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'SSL request started for app.example.com via Let\'s Encrypt.');

        $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

        $this->assertNotNull($certificate);
        $this->assertSame(SiteCertificate::STATUS_ACTIVE, $certificate->status);
        $this->assertSame(['app.example.com'], $certificate->domainHostnames());
    }

    public function test_site_settings_domains_section_can_quick_add_zerossl_ssl(): void
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
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'api.example.com',
            'is_primary' => true,
        ]);

        $this->mock(CertificateRequestService::class, function ($mock) use ($site): void {
            $mock->shouldReceive('create')
                ->once()
                ->withArgs(function (array $attributes) use ($site): bool {
                    return $attributes['site_id'] === $site->id
                        && $attributes['scope_type'] === SiteCertificate::SCOPE_CUSTOMER
                        && $attributes['provider_type'] === SiteCertificate::PROVIDER_ZEROSSL
                        && $attributes['challenge_type'] === SiteCertificate::CHALLENGE_HTTP
                        && $attributes['domains_json'] === ['api.example.com'];
                })
                ->andReturnUsing(function (array $attributes): SiteCertificate {
                    return SiteCertificate::query()->create($attributes);
                });

            $mock->shouldReceive('execute')
                ->once()
                ->andReturnUsing(function (SiteCertificate $certificate): SiteCertificate {
                    $certificate->forceFill([
                        'status' => SiteCertificate::STATUS_ACTIVE,
                        'last_output' => 'ZeroSSL certificate issued and installed.',
                    ])->save();

                    return $certificate->fresh();
                });
        });

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->call('openQuickDomainSslModal', 'api.example.com')
            ->set('quick_ssl_provider_type', SiteCertificate::PROVIDER_ZEROSSL)
            ->call('quickAddDomainSsl')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'SSL request started for api.example.com via ZeroSSL.');

        $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

        $this->assertNotNull($certificate);
        $this->assertSame(SiteCertificate::PROVIDER_ZEROSSL, $certificate->provider_type);
        $this->assertSame(SiteCertificate::STATUS_ACTIVE, $certificate->status);
        $this->assertSame(['api.example.com'], $certificate->domainHostnames());
    }

    public function test_site_settings_certificates_section_can_create_csr(): void
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
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.com',
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'certificates'])
            ->set('new_certificate_provider_type', SiteCertificate::PROVIDER_CSR)
            ->set('new_certificate_challenge_type', SiteCertificate::CHALLENGE_MANUAL)
            ->set('new_certificate_domains', 'app.example.com')
            ->call('createCertificateRequest')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'Certificate request saved.');

        $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

        $this->assertNotNull($certificate);
        $this->assertSame(SiteCertificate::STATUS_ISSUED, $certificate->status);
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) $certificate->csr_pem);
    }

    public function test_site_settings_certificates_section_scopes_dns_request_to_preview_domain(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_private_key' => null,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $previewDomain = SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview-app.dply.cc',
            'is_primary' => true,
            'dns_status' => 'ready',
        ]);
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'certificates'])
            ->set('new_certificate_scope', SiteCertificate::SCOPE_PREVIEW)
            ->set('new_certificate_provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->set('new_certificate_challenge_type', SiteCertificate::CHALLENGE_DNS)
            ->set('new_certificate_preview_domain_id', $previewDomain->id)
            ->set('new_certificate_provider_credential_id', $credential->id)
            ->call('createCertificateRequest')
            ->assertSet('flash_error', 'Server must be ready with an SSH key.');

        $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

        $this->assertNotNull($certificate);
        $this->assertSame([$previewDomain->hostname], $certificate->domainHostnames());
        $this->assertSame(SiteCertificate::SCOPE_PREVIEW, $certificate->scope_type);
    }
}
