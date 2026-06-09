<?php

declare(strict_types=1);

namespace Tests\Feature\SiteCreateRuntimeDetectionTest;

use App\Livewire\Sites\Create as SitesCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\GitCloner;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
test('install missing runtime button appears for uninstalled runtime', function () {
    [$user, $server] = makeServerWithUser();

    // Server's runtime_defaults is empty (the makeServerWithUser helper
    // doesn't seed it), so a Node detection should surface the install
    // affordance.
    fakeClonerThatProducesNodeRepoWithBullmq();

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.git_repository_url', 'https://example.com/jobs.git')
        ->call('detectFromRepository');

    $component->assertSeeHtml('wire:click="installDetectedRuntimeOnServer"');
});
test('install missing runtime button hidden when runtime already installed', function () {
    [$user, $server] = makeServerWithUser();

    // Mark Node as already installed via runtime_defaults — the polyglot
    // wizard preset path. The install affordance should not surface.
    $server->update(['meta' => array_merge((array) $server->meta, [
        'runtime_defaults' => ['node' => '22'],
    ])]);
    fakeClonerThatProducesNodeRepoWithBullmq();

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.git_repository_url', 'https://example.com/jobs.git')
        ->call('detectFromRepository');

    $component->assertDontSeeHtml('wire:click="installDetectedRuntimeOnServer"');
});
test('install missing runtime button hidden for php detection', function () {
    // PHP uses ondrej/php apt, not mise — even though Laravel/PHP isn't
    // in `runtime_defaults`, we don't surface the affordance because
    // the install path is fundamentally different and assumed already
    // configured by ServerProvisionCommandBuilder's PHP install step.
    [$user, $server] = makeServerWithUser();

    $this->app->instance(GitCloner::class, new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            mkdir($destination, 0o755, true);
            file_put_contents(
                $destination.'/composer.json',
                json_encode(['require' => ['laravel/framework' => '^11.0']]),
            );
        }
    });
    unset($this->app[RepositoryRuntimePreview::class]);

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.git_repository_url', 'https://example.com/laravel.git')
        ->call('detectFromRepository');

    $component->assertDontSeeHtml('wire:click="installDetectedRuntimeOnServer"');
});
test('create view shows auto detect panel for non functions host', function () {
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSeeHtml('id="git_repository_url"')
        ->assertSeeHtml('wire:click="detectFromRepository"')
        ->assertSee('Auto-detect');
});
test('create view renders plan details after detection', function () {
    [$user, $server] = makeServerWithUser();
    fakeClonerThatProducesNodeRepoWithBullmq();

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.git_repository_url', 'https://example.com/jobs.git')
        ->call('detectFromRepository');

    $component
        ->assertSee('node')
        ->assertSee('npm run build')
        ->assertSee('npm start')
        ->assertSee('confidence');
});
test('detect from repository pre fills runtime fields from plan', function () {
    [$user, $server] = makeServerWithUser();
    fakeClonerThatProducesNodeRepoWithBullmq();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.git_repository_url', 'https://example.com/jobs.git')
        ->set('form.git_branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('form.runtime', 'node')
        ->assertSet('form.build_command', 'npm run build')
        ->assertSet('form.start_command', 'npm start')
        ->assertSet('form.type', 'node');
});
test('detect from repository does not overwrite user edits', function () {
    [$user, $server] = makeServerWithUser();
    fakeClonerThatProducesNodeRepoWithBullmq();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        // User picks a runtime first, then runs detection.
        ->set('form.runtime', 'python')
        ->set('form.git_repository_url', 'https://example.com/jobs.git')
        ->call('detectFromRepository')
        ->assertSet('form.runtime', 'python');
});
test('detect from repository records clone errors', function () {
    [$user, $server] = makeServerWithUser();
    $this->app->instance(GitCloner::class, new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            throw new GitCloneException('Repository not found.');
        }
    });

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.git_repository_url', 'https://example.com/missing.git')
        ->call('detectFromRepository');

    $plan = $component->get('detectedPlan');
    expect($plan['error'])->toBe('Repository not found.');
});
test('store persists runtime fields for node site', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'jobs-app')
        ->set('form.primary_hostname', 'jobs-app.example.com')
        ->set('form.runtime', 'node')
        ->set('form.runtime_version', '22.7.0')
        ->set('form.type', 'node')
        ->set('form.build_command', 'npm run build')
        ->set('form.start_command', 'npm start')
        ->set('form.git_repository_url', 'https://example.com/jobs.git')
        ->set('form.git_branch', 'main')
        ->set('form.app_port', 3000)
        ->call('store');

    $site = Site::query()->where('name', 'jobs-app')->firstOrFail();
    expect($site->runtime)->toBe('node');
    expect($site->runtime_version)->toBe('22.7.0');
    expect($site->build_command)->toBe('npm run build');
    expect($site->start_command)->toBe('npm start');
    expect($site->git_repository_url)->toBe('https://example.com/jobs.git');
    expect($site->git_branch)->toBe('main');
    expect($site->internal_port)->not->toBeNull();
    expect($site->internal_port)->toBeGreaterThanOrEqual(30000);
    expect($site->internal_port)->toBeLessThanOrEqual(39999);
});
test('store does not allocate internal port for php site', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'laravel-app')
        ->set('form.primary_hostname', 'laravel-app.example.com')
        ->set('form.runtime', 'php')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store');

    $site = Site::query()->where('name', 'laravel-app')->firstOrFail();
    expect($site->runtime)->toBe('php');
    expect($site->internal_port)->toBeNull();
    expect($site->start_command)->toBeNull();
});
test('store backfills web process command from start command', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'fastapi-svc')
        ->set('form.primary_hostname', 'fastapi-svc.example.com')
        ->set('form.runtime', 'python')
        ->set('form.type', 'node')
        ->set('form.start_command', 'uvicorn main:app --host 0.0.0.0 --port 8000')
        ->call('store');

    $site = Site::query()->where('name', 'fastapi-svc')->firstOrFail();
    $webProcess = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();
    expect($webProcess)->not->toBeNull();
    expect($webProcess->command)->toBe('uvicorn main:app --host 0.0.0.0 --port 8000');
});
test('store materializes detected non web processes', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'queue-app')
        ->set('form.primary_hostname', 'queue-app.example.com')
        ->set('form.runtime', 'node')
        ->set('form.type', 'node')
        ->set('form.start_command', 'node server.js')
        ->set('detectedProcesses', [
            [
                'type' => SiteProcess::TYPE_WORKER,
                'name' => 'worker',
                'command' => 'node worker.js',
                'reason' => 'BullMQ detected.',
            ],
        ])
        ->call('store');

    $site = Site::query()->where('name', 'queue-app')->firstOrFail();
    $worker = $site->processes()->where('name', 'worker')->first();
    expect($worker)->not->toBeNull();
    expect($worker->command)->toBe('node worker.js');
    expect($worker->type)->toBe(SiteProcess::TYPE_WORKER);
});
test('store materializes runtime aware default deploy steps', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'rails-app')
        ->set('form.primary_hostname', 'rails-app.example.com')
        ->set('form.runtime', 'ruby')
        ->set('form.runtime_version', '3.3')
        ->set('form.type', 'node')
        ->set('form.start_command', 'bundle exec puma')
        ->set('detectedPlan', ['framework' => 'rails'])
        ->call('store');

    $site = Site::query()->where('name', 'rails-app')->firstOrFail();
    $steps = $site->deploySteps()->orderBy('sort_order')->get();

    expect($steps)->toHaveCount(3);
    $commands = $steps->pluck('custom_command')->all();
    expect($commands)->toContain('bundle install --deployment --without development:test');
    expect($commands)->toContain('bundle exec rails assets:precompile');
    expect($commands)->toContain('bundle exec rails db:migrate');
});
test('store skips default steps for static runtime', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'plain-static')
        ->set('form.primary_hostname', 'plain-static.example.com')
        ->set('form.runtime', 'static')
        ->set('form.type', 'static')
        ->call('store');

    $site = Site::query()->where('name', 'plain-static')->firstOrFail();
    expect($site->deploySteps()->count())->toBe(0);
});
test('engine picker renders only for multi engine servers', function () {
    [$user, $server] = makeServerWithUser();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'mysql84',
        'is_default' => false,
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSeeHtml('id="database_engine"')
        ->assertSeeHtml('wire:model="form.database_engine"');
});
test('engine picker hidden for single engine server', function () {
    [$user, $server] = makeServerWithUser();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertDontSeeHtml('id="database_engine"');
});
test('create form loads server database engines and picks default', function () {
    [$user, $server] = makeServerWithUser();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'mysql84',
        'version' => '8.4',
        'is_default' => false,
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertCount('availableDatabaseEngines', 2)
        ->assertSet('form.database_engine', 'postgres');
});
test('store persists null engine when user keeps server default', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'svc')
        ->set('form.primary_hostname', 'svc.example.com')
        ->set('form.runtime', 'node')
        ->set('form.runtime_version', '22')
        ->set('form.type', 'node')
        ->set('form.start_command', 'npm start')
        // form.database_engine stays at the default that mount() set.
        ->call('store');

    $site = Site::query()->where('name', 'svc')->firstOrFail();
    expect($site->database_engine)->toBeNull();

    // Accessor still returns 'postgres' via fallback.
    expect($site->databaseEngine())->toBe('postgres');
});
test('store persists engine override when user picks non default', function () {
    Queue::fake();
    [$user, $server] = makeServerWithUser();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'mysql84',
        'is_default' => false,
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'mysql-svc')
        ->set('form.primary_hostname', 'mysql-svc.example.com')
        ->set('form.runtime', 'php')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->set('form.database_engine', 'mysql84')
        ->call('store');

    $site = Site::query()->where('name', 'mysql-svc')->firstOrFail();
    expect($site->database_engine)->toBe('mysql84');
    expect($site->databaseEngine())->toBe('mysql84');
});
/**
 * @return array{0: User, 1: Server}
 */
function makeServerWithUser(): array
{
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'webserver' => 'nginx',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4'],
                'detected_default_version' => '8.4',
            ],
            'php_new_site_default_version' => '8.4',
        ],
    ]);

    return [$user, $server];
}
function fakeClonerThatProducesNodeRepoWithBullmq(): void
{
    app()->instance(GitCloner::class, new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            mkdir($destination, 0o755, true);
            file_put_contents(
                $destination.'/package.json',
                json_encode([
                    'name' => 'jobs-app',
                    'dependencies' => ['bullmq' => '^5.0'],
                    'scripts' => [
                        'build' => 'tsc',
                        'start' => 'node dist/server.js',
                        'worker' => 'node dist/worker.js',
                    ],
                ]),
            );
        }
    });

    // RepositoryRuntimePreview is constructed per-request; rebinding the
    // GitCloner above is enough — Livewire will resolve the preview
    // fresh on each call to detectFromRepository.
    unset(app()[RepositoryRuntimePreview::class]);
}
