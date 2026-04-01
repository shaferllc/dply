<?php

namespace Tests\Feature;

use App\Livewire\Sites\Create as SitesCreate;
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

    public function test_site_page_shows_php_card_with_current_version_and_installed_versions(): void
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

    public function test_site_page_shows_php_mismatch_state_and_server_php_remediation_link(): void
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

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('PHP version mismatch')
            ->assertSee('This site references PHP 8.1, but that version is not currently installed on this server.')
            ->assertSee(route('servers.php', $server, false), escape: false)
            ->assertDontSee('value="8.1"', escape: false);
    }

    public function test_site_php_selector_hides_unsupported_installed_versions(): void
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

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('PHP 8.4')
            ->assertDontSee('value="8.5"', escape: false);
    }

    public function test_site_php_settings_can_be_saved_for_installed_versions_only(): void
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
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
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
            ->test(SitesShow::class, ['server' => $server, 'site' => $site->fresh()])
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
            ->set('form.functions_repository_url', 'https://github.com/acme/functions-site.git')
            ->set('form.functions_repository_branch', 'main')
            ->set('form.functions_build_command', 'npm install && npm run build')
            ->set('form.functions_artifact_output_path', 'dist')
            ->set('form.functions_runtime', 'nodejs:18')
            ->set('form.functions_entrypoint', 'index')
            ->call('store')
            ->assertRedirect();

        $site = Site::query()->where('name', 'Functions Site')->firstOrFail();

        $this->assertTrue($site->usesFunctionsRuntime());
        $this->assertSame('digitalocean_functions_web', $site->runtimeProfile());
        $this->assertSame('https://github.com/acme/functions-site.git', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame('nodejs:18', data_get($site->meta, 'digitalocean_functions.runtime'));
        $this->assertSame('index', data_get($site->meta, 'digitalocean_functions.entrypoint'));
        $this->assertSame('npm install && npm run build', data_get($site->meta, 'digitalocean_functions.build_command'));
        $this->assertSame('dist', data_get($site->meta, 'digitalocean_functions.artifact_output_path'));
        $this->assertSame(Site::STATUS_PENDING, $site->status);
        $this->assertSame('queued', $site->provisioningState());
    }

    public function test_functions_host_site_show_hides_server_only_controls(): void
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

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('Functions deploy target')
            ->assertSee('DigitalOcean Functions')
            ->assertSee('Build command')
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

    public function test_remove_domain_can_be_confirmed_through_modal_state(): void
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
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
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
}
