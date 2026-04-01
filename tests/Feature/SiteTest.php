<?php

namespace Tests\Feature;

use App\Livewire\Sites\Create as SitesCreate;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Livewire\Sites\Show as SitesShow;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\SiteDomain;
use App\Models\SiteRelease;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
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

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site, 'section' => 'runtime'], false));

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

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site, 'section' => 'runtime'], false));

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

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site, 'section' => 'runtime'], false));

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
        $this->assertSame('nodejs:18', data_get($site->meta, 'digitalocean_functions.runtime'));
        $this->assertSame('index', data_get($site->meta, 'digitalocean_functions.entrypoint'));
        $this->assertSame('npm install && npm run build', data_get($site->meta, 'digitalocean_functions.build_command'));
        $this->assertSame('dist', data_get($site->meta, 'digitalocean_functions.artifact_output_path'));
        $this->assertSame('vite_static', data_get($site->meta, 'digitalocean_functions.detected_runtime.framework'));
        $this->assertSame(Site::STATUS_PENDING, $site->status);
        $this->assertSame('queued', $site->provisioningState());
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

    public function test_functions_host_site_settings_build_and_deploy_hides_server_only_controls(): void
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
                'digitalocean_functions' => [
                    'artifact_path' => '/tmp/functions-site.zip',
                    'entrypoint' => 'index',
                ],
                'provisioning' => [
                    'state' => 'awaiting_first_deploy',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site, 'section' => 'build-and-deploy'], false));

        $response->assertOk()
            ->assertSee('Functions deploy target')
            ->assertSee('DigitalOcean Functions')
            ->assertDontSee('Install / update Nginx site')
            ->assertDontSee('Issue / renew SSL')
            ->assertDontSee('Push .env to server')
            ->assertDontSee('Generate deploy key');
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
        (new \Symfony\Component\Process\Process(['git', 'init', '-b', 'main'], $origin))->mustRun();
        file_put_contents($origin.'/README.md', "hello\n");
        (new \Symfony\Component\Process\Process(['git', 'add', '.'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();

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
                'digitalocean_functions' => [
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
        $this->assertSame('7', data_get($site->meta, 'digitalocean_functions.last_revision_id'));
        $this->assertNotNull(data_get($site->meta, 'digitalocean_functions.artifact_path'));
        $this->assertStringContainsString('DigitalOcean Functions deploy completed.', (string) $deployment->log_output);
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
        $this->assertSame(Site::STATUS_KUBERNETES_CONFIGURED, $site->status);
        $this->assertStringContainsString('Kubernetes deploy prepared.', (string) $deployment->log_output);
        $this->assertSame('dply-tests', data_get($site->meta, 'kubernetes_runtime.namespace'));
        $this->assertNotEmpty(data_get($site->meta, 'kubernetes_runtime.manifest_yaml'));
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

        (new \Symfony\Component\Process\Process(['git', 'init', '-b', 'main'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'add', '.'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();
        (new \Symfony\Component\Process\Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();

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

    public function test_site_settings_route_redirects_to_general_section(): void
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

        $response->assertRedirect(route('sites.settings', [$server, $site, 'section' => 'general'], false));
    }

    public function test_site_settings_general_section_renders_dedicated_workspace(): void
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

        $response = $this->actingAs($user)->get(route('sites.settings', [$server, $site, 'section' => 'general'], false));

        $response->assertOk()
            ->assertSee('Site settings')
            ->assertSee('General')
            ->assertSee('Domains')
            ->assertSee('Build & deploy')
            ->assertSee('Danger zone')
            ->assertDontSee('Deployment log');
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
            ->assertSee(route('sites.settings', [$server, $site, 'section' => 'general'], false), escape: false)
            ->assertSee('Site settings')
            ->assertSee('Deployment log')
            ->assertDontSee('Save PHP settings')
            ->assertDontSee('Deploy webhook')
            ->assertDontSee('Environment (.env)');
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
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'domains'])
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

    public function test_site_settings_general_section_can_save_primary_domain_and_web_directory(): void
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
            ->assertSet('flash_success', 'Site settings saved.');

        $site->refresh();
        $domain->refresh();

        $this->assertSame('/srv/new/public', $site->document_root);
        $this->assertSame('new.example.com', $domain->hostname);
    }
}
