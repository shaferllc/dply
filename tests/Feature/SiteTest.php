<?php


namespace Tests\Feature\SiteTest;
use App\Contracts\DeployEngine;
use \App\Services\Deploy\SiteRuntimeActionExecutor;
use \App\Services\Deploy\KubernetesKubectlExecutor;
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
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Models\Workspace;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\DockerDeployEngine;
use App\Services\Sites\LaravelSiteSshSetupRunner;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function userWithOrganization(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('site settings runtime section shows php card with current version and installed versions', function () {
    $user = userWithOrganization();
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

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime-php'], false));

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
});

test('site settings deploy section shows docker runtime artifacts', function () {
    $user = userWithOrganization();
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
});

test('site settings deploy section shows kubernetes runtime artifacts', function () {
    $user = userWithOrganization();
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
});

test('site settings runtime section shows php mismatch state and server php remediation link', function () {
    $user = userWithOrganization();
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

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime-php'], false));

    $response->assertOk()
        ->assertSee('PHP version mismatch')
        ->assertSee('This site references PHP 8.1, but that version is not currently installed on this server.')
        ->assertSee(route('servers.php', $server, false), escape: false)
        ->assertDontSee('value="8.1"', escape: false);
});

test('site settings runtime section hides unsupported installed versions', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    // 5.6 is far below the supported floor (server_provision_options
    // lists 8.5 / 8.4 / 8.3 / 7.4); 8.5 and 7.4 are still listed
    // in the config so they don't make good "unsupported" fixtures.
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '5.6'],
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

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime-php'], false));

    $response->assertOk()
        ->assertSee('PHP 8.4')
        ->assertDontSee('value="5.6"', escape: false);
});

test('site php settings can be saved from site settings for installed versions only', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'PHP settings saved.', type: 'success');

    $site->refresh();

    expect($site->php_version)->toBe('8.4');
    expect(data_get($site->meta, 'php_runtime.memory_limit'))->toBe('512M');
    expect(data_get($site->meta, 'php_runtime.upload_max_filesize'))->toBe('128M');
    expect((string) data_get($site->meta, 'php_runtime.max_execution_time'))->toBe('120');

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site->fresh(), 'section' => 'runtime'])
        ->set('php_version', '8.1')
        ->call('savePhpSettings')
        ->assertHasErrors(['php_version']);
});

test('php site creation prefills the valid server new site default', function () {
    $user = userWithOrganization();
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
});

test('site creation reconfigures paths when stack changes', function () {
    $user = userWithOrganization();
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
});

test('site creation keeps auto paths hidden until customized', function () {
    $user = userWithOrganization();
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
});

test('php site creation requires explicit selection when saved default is not installed', function () {
    $user = userWithOrganization();
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
});

test('site creation queues async provisioning and redirects to site show', function () {
    Queue::fake();

    $user = userWithOrganization();
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

    expect($site->provisioningState())->toBe('queued');
    expect(data_get($site->meta, 'provisioning.webserver'))->toBe('nginx');

    Queue::assertPushed(ProvisionSiteJob::class, function (ProvisionSiteJob $job) use ($site): bool {
        return $job->siteId === $site->id && $job->probeAttempt === 0;
    });
});

test('functions host site creation uses runtime profile and artifact metadata', function () {
    Queue::fake();

    $origin = makeGitRepository([
        'package.json' => json_encode([
            'scripts' => [
                'build' => 'vite build',
            ],
            'dependencies' => [
                'vite' => '^5.0.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    ]);

    $user = userWithOrganization();
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

    expect($site->usesFunctionsRuntime())->toBeTrue();
    expect($site->runtimeProfile())->toBe('digitalocean_functions_web');
    expect($site->git_repository_url)->toBe($origin);
    expect($site->git_branch)->toBe('main');
    expect(data_get($site->meta, 'serverless.runtime'))->toBe('nodejs:18');
    expect(data_get($site->meta, 'serverless.entrypoint'))->toBe('index');
    expect(data_get($site->meta, 'serverless.build_command'))->toBe('npm install && npm run build');
    expect(data_get($site->meta, 'serverless.artifact_output_path'))->toBe('dist');
    expect(data_get($site->meta, 'serverless.detected_runtime.framework'))->toBe('vite_static');
    expect($site->status)->toBe(Site::STATUS_PENDING);
    expect($site->provisioningState())->toBe('queued');
});

test('aws lambda host site creation marks laravel repo as supported', function () {
    Queue::fake();

    $origin = makeGitRepository([
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

    $user = userWithOrganization();
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

    expect($site->usesAwsLambdaRuntime())->toBeTrue();
    expect($site->runtimeProfile())->toBe('aws_lambda_bref_web');
    expect(data_get($site->meta, 'serverless.runtime'))->toBe('provided.al2023');
    expect(data_get($site->meta, 'serverless.entrypoint'))->toBe('public/index.php');
    expect(data_get($site->meta, 'serverless.detected_runtime.framework'))->toBe('laravel');
});

test('docker host site creation uses docker runtime profile', function () {
    Queue::fake();

    $user = userWithOrganization();
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

    expect($site->usesDockerRuntime())->toBeTrue();
    expect($site->runtimeProfile())->toBe('docker_web');
    expect($site->type->value)->toBe('php');
    expect($site->php_version)->toBeNull();
    expect($site->provisioningState())->toBe('queued');
});

test('kubernetes host site creation uses kubernetes runtime profile', function () {
    Queue::fake();

    $user = userWithOrganization();
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

    expect($site->usesKubernetesRuntime())->toBeTrue();
    expect($site->runtimeProfile())->toBe('kubernetes_web');
    expect(data_get($site->meta, 'kubernetes_runtime.namespace'))->toBe('apps');
    expect($site->provisioningState())->toBe('queued');
});

test('functions host site creation blocks laravel repo on unsupported target', function () {
    Queue::fake();

    $origin = makeGitRepository([
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

    $user = userWithOrganization();
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

    expect(Site::query()->where('name', 'Laravel Functions Site')->first())->toBeNull();
    Queue::assertNotPushed(ProvisionSiteJob::class);
});

test('functions host site settings deploy hides server only controls', function () {
    // The deploy *config* tab for a functions site only exposes the
    // recipe (repo URL / branch / build command / pipeline / hooks);
    // serverless invocation metadata (target, function URL, runtime,
    // ARN) lives on the Overview / serverless dashboard, not here.
    $user = userWithOrganization();
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
        // Positive: confirm we're on the functions-flavored deploy config tab.
        // The "Deploy command" label and "Repository subdirectory" field
        // only render when the site is a functions host.
        ->assertSee('Deploy command')
        ->assertSee('Repository subdirectory')
        // Negative: server-only controls must not appear.
        ->assertDontSee('Install / update Nginx site')
        ->assertDontSee('Issue / renew SSL')
        ->assertDontSee('Push .env to server')
        ->assertDontSee('Generate deploy key');
});

test('aws lambda site settings deploy renders recipe only', function () {
    // Lambda-specific invocation metadata (target = AWS Lambda, function
    // ARN, runtime) now lives on the Overview / serverless dashboard.
    // The deploy config tab is recipe-only and should render cleanly for
    // a Lambda site without breaking on missing host concepts.
    $user = userWithOrganization();
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
        ->assertSee('Deploy command')
        ->assertSee('Repository subdirectory')
        // Invocation metadata moved to Overview — must not leak back here.
        ->assertDontSee('Function ARN')
        ->assertDontSee('Latest managed artifact');
});

test('functions host deploy uses digitalocean functions engine', function () {
    Http::fake([
        'https://faas-nyc1-example.doserverless.co/api/v1/namespaces/*' => Http::response([
            'version' => '7',
        ], 200),
        // The post-deploy health check GETs the web invocation URL.
        'https://faas-nyc1-example.doserverless.co/api/v1/web/*' => Http::response('ok', 200),
    ]);

    $origin = storage_path('framework/testing/functions-deploy-repo-'.uniqid());
    mkdir($origin, 0777, true);
    (new Process(['git', 'init', '-b', 'main'], $origin))->mustRun();
    file_put_contents($origin.'/README.md', "hello\n");
    (new Process(['git', 'add', '.'], $origin))->mustRun();
    (new Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();
    (new Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();

    $user = userWithOrganization();
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

    expect($deployment->status)->toBe(SiteDeployment::STATUS_SUCCESS);
    expect($deployment->git_sha)->toBe('7');
    expect($site->status)->toBe(Site::STATUS_FUNCTIONS_ACTIVE);
    expect(data_get($site->meta, 'serverless.last_revision_id'))->toBe('7');
    expect(data_get($site->meta, 'serverless.artifact_path'))->not->toBeNull();
    $this->assertStringContainsString('DigitalOcean Functions deploy completed.', (string) $deployment->log_output);

    // The post-deploy health check ran and the function answered.
    $this->assertStringContainsString('Health check: HTTP 200', (string) $deployment->log_output);

    // The action API uses the `_` namespace placeholder (not the literal
    // namespace, which 404s) and marks the action web-exported.
    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }
        $annotations = collect($request['annotations'] ?? []);

        return str_contains($request->url(), '/api/v1/namespaces/_/actions/')
            && ! str_contains($request->url(), 'fn-namespace/actions')
            // exec.main is the OpenWhisk handler function name — dply's
            // runtimes export `main`, never the `index` file basename.
            && data_get($request->data(), 'exec.main') === 'main'
            && $annotations->contains(fn ($a) => ($a['key'] ?? null) === 'web-export' && ($a['value'] ?? null) === true);
    });
});

test('aws lambda host deploy uses aws lambda engine', function () {
    $origin = makeGitRepository([
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

    $user = userWithOrganization();
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

    expect($deployment->status)->toBe(SiteDeployment::STATUS_SUCCESS);
    expect($deployment->git_sha)->toBe('aws-stub-revision-1');
    expect($site->status)->toBe(Site::STATUS_FUNCTIONS_ACTIVE);
    expect(data_get($site->meta, 'serverless.target'))->toBe(Server::HOST_KIND_AWS_LAMBDA);
    expect(data_get($site->meta, 'serverless.last_revision_id'))->toBe('aws-stub-revision-1');
    expect(data_get($site->meta, 'serverless.artifact_path'))->not->toBeNull();
    $this->assertStringContainsString('AWS Lambda deploy completed.', (string) $deployment->log_output);
});

test('functions configured state is ready for workspace but not traffic', function () {
    $site = new Site([
        'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
        ],
    ]);

    expect($site->isReadyForWorkspace())->toBeTrue();
    expect($site->isReadyForTraffic())->toBeFalse();
    expect($site->statusLabel())->toBe('functions configured');
});

test('docker host site provisioning prepares runtime until first deploy', function () {
    $user = userWithOrganization();
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
        'git_repository_url' => 'https://github.com/example/laravel-app.git',
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

    expect($site->status)->toBe(Site::STATUS_DOCKER_CONFIGURED);
    expect($site->provisioningState())->toBe('awaiting_first_deploy');
    expect(data_get($site->meta, 'docker_runtime.compose_yaml'))->not->toBeEmpty();
});

test('docker host deploy uses docker engine', function () {
    app()->instance(DockerDeployEngine::class, new class implements DeployEngine
    {
        function run(DeployContext $context): array
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

    $user = userWithOrganization();
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

    expect($deployment->status)->toBe(SiteDeployment::STATUS_SUCCESS);
    expect($site->status)->toBe(Site::STATUS_DOCKER_CONFIGURED);
    $this->assertStringContainsString('Docker deploy prepared.', (string) $deployment->log_output);
    expect(data_get($site->meta, 'docker_runtime.compose_yaml'))->not->toBeEmpty();
});

test('kubernetes host deploy uses kubernetes engine', function () {
    app()->instance(KubernetesKubectlExecutor::class, new class extends KubernetesKubectlExecutor
    {
        function deploy(string $manifest, string $namespace, string $deploymentName, ?string $kubeconfigPath = null, ?string $context = null): array
        {
            return [
                'output' => "namespace/{$namespace} unchanged\ndeployment.apps/{$deploymentName} configured\ndeployment \"{$deploymentName}\" successfully rolled out",
                'revision' => '3',
                'context' => $context ?? 'orbit-local',
            ];
        }
    });

    $user = userWithOrganization();
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

    expect($deployment->status)->toBe(SiteDeployment::STATUS_SUCCESS);
    expect($deployment->git_sha)->toBe('3');
    expect($site->status)->toBe(Site::STATUS_KUBERNETES_ACTIVE);
    $this->assertStringContainsString('Kubernetes deploy applied.', (string) $deployment->log_output);
    expect(data_get($site->meta, 'kubernetes_runtime.namespace'))->toBe('dply-tests');
    expect(data_get($site->meta, 'kubernetes_runtime.manifest_yaml'))->not->toBeEmpty();
    expect(data_get($site->meta, 'kubernetes_runtime.deployment_name'))->toBe(Str::slug($site->slug ?: $site->name));
    expect(data_get($site->meta, 'kubernetes_runtime.last_revision_id'))->toBe('3');
    expect(data_get($site->meta, 'kubernetes_runtime.kubectl_context'))->toBe('orbit-local');
});

/**
 * @param  array<string, string>  $files
 */
function makeGitRepository(array $files): string
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

test('site show renders provisioning status card', function () {
    $user = userWithOrganization();
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
        ->assertSee('Site provisioning')
        ->assertSee('Checking reachability')
        ->assertSee('preview-app.dply.cc');
});

test('site settings route redirects legacy webhooks section to notifications', function () {
    // The legacy /servers/{server}/sites/{site}/settings/{section} URL now lives
    // only as a redirect for renamed sections (webhooks → notifications;
    // domains/aliases/redirects/preview/tenants → routing). A request without a
    // section is handled by the wildcard SiteSettings route directly and does
    // not 302 anywhere, so the older "bare URL → general" test no longer matches
    // real behavior; this version exercises the actual rename path that still
    // exists in routes/web.php.
    $user = userWithOrganization();
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

    $response = $this->actingAs($user)->get(route('sites.settings', [
        'server' => $server,
        'site' => $site,
        'section' => 'webhooks',
    ], false));

    $response->assertRedirect(route('sites.show', [
        'server' => $server,
        'site' => $site,
        'section' => 'notifications',
    ], false));
});

test('site show defaults to general site workspace', function () {
    $user = userWithOrganization();
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
});

test('site show surfaces deployment foundation preflight and resource state', function () {
    $user = userWithOrganization();
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
});

test('site environment section renders keys for serverless sites', function () {
    $user = userWithOrganization();
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

    // Functions-backed sites have no host .env, so the cache IS the truth
    // and the page hides Sync/Push CTAs but keeps the keys list usable.
    // The explainer paragraph mentions the verbs as concepts; check for the
    // CTA wire:click handlers to confirm the actual buttons are absent.
    $response->assertOk()
        ->assertSee('Environment variables')
        ->assertSee('APP_KEY')
        ->assertSee('APP_NAME')
        ->assertDontSee('wire:click="syncEnvFromServer"', false)
        ->assertDontSee('wire:click="pushEnvToServer"', false);
});

test('site settings legacy routing section redirects to routing tab', function () {
    $user = userWithOrganization();
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
});

test('site settings deploy section shows no downtime scripts hooks variables and log links', function () {
    $user = userWithOrganization();
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
        ->assertSee('Zero downtime deployment')
        ->assertSee('After deploy verification')
        ->assertSee('Pre-deploy script')
        ->assertSee('Main deploy script')
        ->assertSee('Post-deploy script')
        ->assertSee('Deploy hooks')
        ->assertSee('Deploy script variables')
        ->assertSee('{SITE_DOMAIN}')
        ->assertSee('{BRANCH}')
        ->assertSee(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs'], false), escape: false)
        ->assertSee(route('servers.logs', $server, false), escape: false);
});

test('site settings deploy section can save repository and strategy settings', function () {
    Bus::fake();

    $user = userWithOrganization();
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
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'confidence' => 'high',
                    'laravel_octane' => true,
                ],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'deploy'])
        ->set('git_repository_url', 'git@github.com:acme/example.git')
        ->set('git_branch', 'release')
        ->set('post_deploy_command', 'php artisan optimize')
        ->call('saveGit')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Git settings saved.', type: 'success')
        ->set('zero_downtime_enabled', true)
        ->call('saveZeroDowntimeDeployment')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Zero downtime deployment settings saved. Webserver config reloaded.', type: 'success')
        ->set('releases_to_keep', 8)
        ->set('deployment_environment', 'staging')
        ->set('octane_port', '8080')
        ->set('laravel_scheduler', true)
        ->set('restart_supervisor_programs_after_deploy', true)
        ->set('nginx_extra_raw', 'location /health { return 200; }')
        ->call('saveDeploymentSettings')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Deployment / Nginx settings saved. Re-install Nginx if you changed redirects, Octane, or extra config. Re-sync server crontab for Laravel scheduler. When “Restart Supervisor after deploy” is on, Dply restarts programs for this site (and server-wide programs) after a successful deploy.', type: 'success')
        ->set('php_fpm_user', 'deploy')
        ->call('saveSystemUserSettings')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: __('System user settings saved.'), type: 'success');

    Bus::assertDispatched(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);

    $site->refresh();

    expect($site->git_repository_url)->toBe('git@github.com:acme/example.git');
    expect($site->git_branch)->toBe('release');
    expect($site->post_deploy_command)->toBe('php artisan optimize');
    expect($site->deploy_strategy)->toBe('atomic');
    expect($site->releases_to_keep)->toBe(8);
    expect($site->deployment_environment)->toBe('staging');
    expect($site->octane_port)->toBe(8080);
    expect($site->php_fpm_user)->toBe('deploy');
    expect($site->laravel_scheduler)->toBeTrue();
    expect($site->restart_supervisor_programs_after_deploy)->toBeTrue();
    expect($site->nginx_extra_raw)->toBe('location /health { return 200; }');
    $meta = is_array($site->meta) ? $site->meta : [];
    expect($meta['deploy_health_expect_status'] ?? null)->toBe(200);
    expect($meta['deploy_health_attempts'] ?? null)->toBe(5);
    expect((bool) ($meta['deploy_health_enabled'] ?? false))->toBeFalse();
});

test('site settings general section uses certificate summary for ssl status', function () {
    $user = userWithOrganization();
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
});

test('site settings general section shows project context links', function () {
    $user = userWithOrganization();
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
});

test('site settings general section shows site details and notes', function () {
    $user = userWithOrganization();
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
});

test('site settings component rejects unknown section', function () {
    $user = userWithOrganization();
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
});

test('site show links to dedicated settings workspace and omits settings forms', function () {
    $user = userWithOrganization();
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
});

test('site show displays aws lambda runtime target details', function () {
    $user = userWithOrganization();
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
});

test('site show displays docker runtime target summary', function () {
    $user = userWithOrganization();
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
});

test('site show displays kubernetes runtime target summary', function () {
    $user = userWithOrganization();
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
});

test('runtime target model maps local and cloud container families', function () {
    $user = userWithOrganization();
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

    expect($localSite->runtimeTargetFamily())->toBe('local_orbstack_docker');
    expect($localSite->runtimeTargetPlatform())->toBe('local');
    expect($localSite->usesLocalDockerHostRuntime())->toBeTrue();

    expect($digitalOceanSite->runtimeTargetFamily())->toBe('digitalocean_kubernetes');
    expect($digitalOceanSite->runtimeTargetPlatform())->toBe('digitalocean');
    expect($digitalOceanSite->runtimeTargetMode())->toBe('kubernetes');
});

test('site show exposes orbstack runtime controls and records runtime actions', function () {
    app()->instance(SiteRuntimeActionExecutor::class, new class extends SiteRuntimeActionExecutor
    {
        function __construct()
        {
        }

        function run(Site $site, string $action): array
        {
            return [
                'status' => 'running',
                'output' => 'Stub runtime action output for '.$action,
            ];
        }
    });

    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Runtime status refreshed.', type: 'success');

    $site->refresh();

    expect(data_get($site->meta, 'runtime_target.last_operation'))->toBe('status');
    $this->assertStringContainsString('Stub runtime action output', (string) data_get($site->meta, 'runtime_target.last_operation_output'));
    expect(data_get($site->meta, 'runtime_target.logs'))->not->toBeEmpty();
});

test('site show surfaces runtime error console from error diagnostics', function () {
    $user = userWithOrganization();
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
});

test('site settings runtime section shows docker management and discovery', function () {
    $user = userWithOrganization();
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
        ->assertSee('Back to apps')
        ->assertSee('Overview')
        ->assertSee('Deployments')
        ->assertSee('Repository')
        ->assertSee('Networking')
        ->assertDontSee('Certificates')
        ->assertSee('Docker discovery')
        ->assertSee('Container lifecycle')
        ->assertSee('Refresh Docker details')
        ->assertSee('laravel.repo.orb.local')
        ->assertSee('192.168.107.2')
        ->assertSee('laravel.repo');
});

test('site settings general section renders container dashboard for cloud app', function () {
    // Container workspaces (docker / k8s) get the dedicated container-dashboard
    // partial on Overview — backend / region / port / instances / size / live URL
    // — instead of the VM-shaped overview. The VM-shaped read-only overview,
    // Status, project context, and Networking group are deliberately absent:
    // those concepts belong to the dply edge or the operator's artifact, not
    // to this workspace.
    $user = userWithOrganization();
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
        // Container-dashboard labels (the new Overview shape).
        ->assertSee('Container deployment')
        ->assertSee('Backend')
        ->assertSee('Live URL')
        // The Overview sidebar item still labels itself "Overview" for container sites.
        ->assertSee('Overview')
        // VM-shaped overview content stays hidden for container workspaces.
        ->assertDontSee('Primary hostname')
        ->assertDontSee('App details')
        // Networking is no longer a sidebar group for container workspaces —
        // routing/DNS/certificates belong to the dply edge, not this workspace.
        ->assertDontSee('>Networking<', false);
});

test('refresh docker details persists discovered runtime metadata', function () {
    app()->instance(SiteRuntimeActionExecutor::class, new class extends SiteRuntimeActionExecutor
    {
        function __construct()
        {
        }

        function run(Site $site, string $action): array
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

    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Docker details refreshed.', type: 'success');

    $site->refresh();

    expect(data_get($site->meta, 'runtime_target.last_operation'))->toBe('inspect');
    expect(data_get($site->meta, 'runtime_target.publication.hostname'))->toBe('laravel.repo.orb.local');
    expect(data_get($site->meta, 'runtime_target.publication.container_ip'))->toBe('192.168.107.2');
    expect(data_get($site->meta, 'docker_runtime.runtime_details.containers.0.orb_hostname'))->toBe('laravel.repo.orb.local');
});

test('site show records failed orbstack runtime actions with debug output', function () {
    app()->instance(SiteRuntimeActionExecutor::class, new class extends SiteRuntimeActionExecutor
    {
        function __construct()
        {
        }

        function run(Site $site, string $action): array
        {
            throw new \RuntimeException("docker compose failed\n\nWorking directory: /tmp/demo\nCommand: docker compose up -d");
        }
    });

    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: "docker compose failed\n\nWorking directory: /tmp/demo\nCommand: docker compose up -d", type: 'error');

    $site->refresh();

    expect(data_get($site->meta, 'runtime_target.last_operation_status'))->toBe('failed');
    expect(data_get($site->meta, 'runtime_target.last_operation'))->toBe('rebuild');
    $this->assertStringContainsString('Working directory: /tmp/demo', (string) data_get($site->meta, 'runtime_target.last_operation_output'));
    expect(data_get($site->meta, 'runtime_target.logs'))->not->toBeEmpty();
    expect(data_get($site->meta, 'runtime_target.logs.0.status'))->toBe('failed');
});

test('site show displays preview and certificate summary', function () {
    $user = userWithOrganization();
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
});

test('site show displays certificate retry affordance for failed certificate', function () {
    $user = userWithOrganization();
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
});

test('site show can retry a failed certificate', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Certificate retry finished.', type: 'success');

    $certificate->refresh();

    expect($certificate->status)->toBe(SiteCertificate::STATUS_ISSUED);
    expect($certificate->last_output)->toBe('CSR regenerated successfully');
});

test('sites index shows provisioning badge and visit link only when ready', function () {
    $user = userWithOrganization();
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
});

test('release deploy lock uses confirmation modal', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Deploy lock cleared. If a worker is still running, stop it on the queue host; otherwise you can deploy again.', type: 'success');

    expect(cache()->get('site-deploy-active:'.$site->id))->toBeNull();
});

test('site settings domains section can remove domain through modal state', function () {
    Bus::fake();

    $user = userWithOrganization();
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
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
});

test('site settings aliases section can add alias', function () {
    Bus::fake();

    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Alias added. Webserver config queued.', type: 'success');

    $this->assertDatabaseHas('site_domain_aliases', [
        'site_id' => $site->id,
        'hostname' => 'www.example.com',
        'label' => 'Marketing alias',
    ]);
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
});

test('site settings tenants section can add tenant domain', function () {
    Bus::fake();

    $user = userWithOrganization();
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
        ->set('new_tenant_comment', 'App resolver uses the hostname.')
        ->call('addTenantDomain')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Tenant domain added. Webserver config queued.', type: 'success');

    $this->assertDatabaseHas('site_tenant_domains', [
        'site_id' => $site->id,
        'hostname' => 'acme.example.com',
        'tenant_key' => 'acme',
        'label' => 'Acme',
    ]);
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
});

test('site settings redirects section renders separately', function () {
    $user = userWithOrganization();
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

    // Manual "Apply webserver config now" button was removed when the
    // Routing page adopted the env-page UX (auto-apply on every save).
    // The page still renders the section header, the Add CTA, and the
    // "Auto-applied to the webserver after save." note in the modal.
    $response->assertOk()
        ->assertSee('Redirects')
        ->assertSee('Add redirect');
});

test('site show can add internal redirect', function () {
    $user = userWithOrganization();
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

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $server, 'site' => $site])
        ->set('new_redirect_kind', 'internal_rewrite')
        ->set('new_redirect_from', '/legacy')
        ->set('new_redirect_to', '/new')
        ->call('addRedirectRule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('site_redirects', [
        'site_id' => $site->id,
        'kind' => 'internal_rewrite',
        'from_path' => '/legacy',
        'to_url' => '/new',
    ]);
});

test('site settings notifications section can save ip allow list', function () {
    $user = userWithOrganization();
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
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'notifications'])
        ->set('webhook_allowed_ips_text', "203.0.113.10\n192.0.2.0/24")
        ->call('saveWebhookSecurity')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Webhook IP allow list saved. Leave empty to allow any source (signature still required).', type: 'success');

    $site->refresh();

    expect($site->webhook_allowed_ips)->toBe(['203.0.113.10', '192.0.2.0/24']);
});

test('site settings logs section renders site deployments and webhook deliveries', function () {
    $user = userWithOrganization();
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
        ->assertSee('Deploy completed successfully.')
        ->assertSee('Accepted deploy webhook.')
        ->assertSee('Open server logs')
        ->assertSee(route('servers.logs', $server, false), escape: false);
});

test('site settings general section can save primary domain and web directory', function () {
    Bus::fake();

    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Site settings saved. Webserver config reloaded.', type: 'success');

    $site->refresh();
    $domain->refresh();

    expect($site->document_root)->toBe('/srv/new/public');
    expect($domain->hostname)->toBe('new.example.com');
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
});

test('site project settings can assign workspace', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Project settings saved.', type: 'success');

    $site->refresh();

    expect($site->workspace_id)->toBe($workspace->id);
});

test('site project settings reject workspace from another organization', function () {
    $user = userWithOrganization();
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
});

test('site settings runtime section can save php version', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'PHP settings saved.', type: 'success');

    $site->refresh();

    expect($site->php_version)->toBe('8.4');
});

test('site settings runtime section hides php workspace for non php sites', function () {
    $user = userWithOrganization();
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
});

test('site settings general section can save site notes', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Site notes saved.', type: 'success');

    $site->refresh();

    expect(data_get($site->meta, 'notes'))->toBe('Remember the vendor firewall allow list.');
});

test('site settings preview section can save primary preview domain', function () {
    Bus::fake();

    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Preview settings saved. Webserver config reloaded.', type: 'success');

    $site->refresh();
    $previewDomain = SitePreviewDomain::query()->where('site_id', $site->id)->first();

    expect($previewDomain)->not->toBeNull();
    expect($previewDomain->hostname)->toBe('preview-new.dply.cc');
    expect($previewDomain->is_primary)->toBeTrue();
    expect($site->testingHostname())->toBe('preview-new.dply.cc');
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class, fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === $site->id);
});

test('site settings domains section shows quick ssl action only for uncovered domains', function () {
    $user = userWithOrganization();
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
});

test('site settings aliases section shows quick ssl action only for uncovered aliases', function () {
    $user = userWithOrganization();
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
    SiteDomainAlias::query()->create([
        'site_id' => $site->id,
        'hostname' => 'alias.example.com',
        'label' => 'Marketing',
    ]);
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => ['app.example.com'],
        'status' => SiteCertificate::STATUS_ACTIVE,
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'aliases'], false));

    $response->assertOk()
        ->assertSee('SSL missing')
        ->assertSee("openQuickDomainSslModal('alias.example.com')", escape: false)
        ->assertDontSee("openQuickDomainSslModal('app.example.com')", escape: false);
});

test('site settings aliases section can quick add letsencrypt ssl for alias', function () {
    $user = userWithOrganization();
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
    SiteDomainAlias::query()->create([
        'site_id' => $site->id,
        'hostname' => 'alias.example.com',
        'label' => 'Marketing',
    ]);

    $this->mock(CertificateRequestService::class, function ($mock) use ($site): void {
        $mock->shouldReceive('create')
            ->once()
            ->withArgs(function (array $attributes) use ($site): bool {
                return $attributes['site_id'] === $site->id
                    && $attributes['scope_type'] === SiteCertificate::SCOPE_CUSTOMER
                    && $attributes['provider_type'] === SiteCertificate::PROVIDER_LETSENCRYPT
                    && $attributes['challenge_type'] === SiteCertificate::CHALLENGE_HTTP
                    && $attributes['domains_json'] === ['alias.example.com'];
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
        ->set('routingTab', 'aliases')
        ->call('openQuickDomainSslModal', 'alias.example.com')
        ->assertSet('quick_ssl_domain_hostname', 'alias.example.com')
        ->set('quick_ssl_provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
        ->call('quickAddDomainSsl')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'SSL request started for alias.example.com via Let\'s Encrypt.', type: 'success');

    $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

    expect($certificate)->not->toBeNull();
    expect($certificate->status)->toBe(SiteCertificate::STATUS_ACTIVE);
    expect($certificate->domainHostnames())->toBe(['alias.example.com']);
});

test('site settings domains section can quick add letsencrypt ssl', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'SSL request started for app.example.com via Let\'s Encrypt.', type: 'success');

    $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

    expect($certificate)->not->toBeNull();
    expect($certificate->status)->toBe(SiteCertificate::STATUS_ACTIVE);
    expect($certificate->domainHostnames())->toBe(['app.example.com']);
});

test('site settings domains section can quick add zerossl ssl', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'SSL request started for api.example.com via ZeroSSL.', type: 'success');

    $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

    expect($certificate)->not->toBeNull();
    expect($certificate->provider_type)->toBe(SiteCertificate::PROVIDER_ZEROSSL);
    expect($certificate->status)->toBe(SiteCertificate::STATUS_ACTIVE);
    expect($certificate->domainHostnames())->toBe(['api.example.com']);
});

test('site settings certificates section can create csr', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Certificate request saved.', type: 'success');

    $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

    expect($certificate)->not->toBeNull();
    expect($certificate->status)->toBe(SiteCertificate::STATUS_ISSUED);
    $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) $certificate->csr_pem);
});

test('site settings certificates section scopes dns request to preview domain', function () {
    $user = userWithOrganization();
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
        ->assertDispatched('notify', message: 'Server must be ready with an SSH key.', type: 'error');

    $certificate = SiteCertificate::query()->where('site_id', $site->id)->latest('created_at')->first();

    expect($certificate)->not->toBeNull();
    expect($certificate->domainHostnames())->toBe([$previewDomain->hostname]);
    expect($certificate->scope_type)->toBe(SiteCertificate::SCOPE_PREVIEW);
});

test('site dns settings page renders and saves credential', function () {
    $user = userWithOrganization();
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
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Primary DO',
    ]);

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'dns'], false))
        ->assertOk()
        ->assertSee('DNS automation', escape: false);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'dns'])
        ->set('settings_dns_zone', '')
        ->set('settings_dns_provider_credential_id', $credential->id)
        ->call('saveDnsSettings')
        ->assertHasNoErrors();

    expect($site->fresh()->dns_provider_credential_id)->toBe($credential->id);
    expect($site->fresh()->dns_zone)->toBeNull();
});

test('site dns settings rejects credential from another organization', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $otherOrg = Organization::factory()->create();
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
    $foreignCredential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $otherOrg->id,
        'provider' => 'digitalocean',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'dns'])
        ->set('settings_dns_zone', '')
        ->set('settings_dns_provider_credential_id', $foreignCredential->id)
        ->call('saveDnsSettings')
        ->assertHasErrors(['settings_dns_provider_credential_id']);

    expect($site->fresh()->dns_provider_credential_id)->toBeNull();
});

test('site dns settings saves zone when domain exists in digitalocean', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.com' => Http::response([
            'domain' => ['name' => 'example.com'],
        ], 200),
    ]);

    $user = userWithOrganization();
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
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Primary DO',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'dns'])
        ->set('settings_dns_zone', 'example.com')
        ->set('settings_dns_provider_credential_id', $credential->id)
        ->call('saveDnsSettings')
        ->assertHasNoErrors();

    expect($site->fresh()->dns_zone)->toBe('example.com');
});

test('site dns settings rejects zone not in digitalocean account', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/missing.example' => Http::response(['message' => 'Not found'], 404),
    ]);

    $user = userWithOrganization();
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
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'dns'])
        ->set('settings_dns_zone', 'missing.example')
        ->call('saveDnsSettings')
        ->assertHasErrors(['settings_dns_zone']);

    expect($site->fresh()->dns_zone)->toBeNull();
});

test('site guess dns zone from primary hostname', function () {
    $user = userWithOrganization();
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
        'www_redirect' => false,
    ]);

    expect($site->fresh()->guessDnsZoneFromPrimaryHostname())->toBe('example.com');
});

test('site settings open laravel ssh setup modal sets pending action and command preview', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                ],
            ],
        ],
    ]);

    $test = Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack']);

    $test->call('openLaravelSshSetupModal', LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL)
        ->assertSet('laravel_ssh_setup_pending_action', LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL)
        ->assertSet('laravel_ssh_setup_error', null);

    $preview = $test->instance()->laravelSshSetupPendingCommandPreview();
    expect($preview)->toBeString();
    $this->assertStringContainsString('composer install --no-dev', $preview);
    $this->assertStringContainsString(escapeshellarg($site->effectiveEnvDirectory()), $preview);
});

test('site settings laravel ssh setup confirm is blocked for deployer', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                ],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
        ->set('laravel_ssh_setup_pending_action', LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL)
        ->call('confirmLaravelSshSetup')
        ->assertSet('laravel_ssh_setup_error', __('Deployers cannot run remote setup commands on servers.'));
});

test('site settings suspend and resume updates site and applies webserver config', function () {
    $user = userWithOrganization();
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
        'hostname' => 'app.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $this->mock(SiteWebserverConfigApplier::class, function ($mock): void {
        $mock->shouldReceive('apply')->twice()->andReturn('ok');
    });

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'danger'])
        ->set('settings_suspended_message', 'Payment pending')
        ->call('suspendSite')
        ->assertHasNoErrors();

    $site->refresh();
    expect($site->suspended_at)->not->toBeNull();
    $meta = is_array($site->meta) ? $site->meta : [];
    expect($meta['suspended_message'] ?? null)->toBe('Payment pending');
    expect($site->suspended_reason)->toBeNull();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site->fresh(), 'section' => 'danger'])
        ->call('resumeSite')
        ->assertHasNoErrors();

    $site->refresh();
    expect($site->suspended_at)->toBeNull();
    expect($site->suspended_reason)->toBeNull();
    $meta = is_array($site->meta) ? $site->meta : [];
    $this->assertArrayNotHasKey('suspended_message', $meta);
});