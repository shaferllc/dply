<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Tests\TestCase;

class SiteCreateRuntimeDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_install_missing_runtime_button_appears_for_uninstalled_runtime(): void
    {
        [$user, $server] = $this->makeServerWithUser();
        // Server's runtime_defaults is empty (the makeServerWithUser helper
        // doesn't seed it), so a Node detection should surface the install
        // affordance.
        $this->fakeClonerThatProducesNodeRepoWithBullmq();

        $component = Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.git_repository_url', 'https://example.com/jobs.git')
            ->call('detectFromRepository');

        $component->assertSeeHtml('wire:click="installDetectedRuntimeOnServer"');
    }

    public function test_install_missing_runtime_button_hidden_when_runtime_already_installed(): void
    {
        [$user, $server] = $this->makeServerWithUser();
        // Mark Node as already installed via runtime_defaults — the polyglot
        // wizard preset path. The install affordance should not surface.
        $server->update(['meta' => array_merge((array) $server->meta, [
            'runtime_defaults' => ['node' => '22'],
        ])]);
        $this->fakeClonerThatProducesNodeRepoWithBullmq();

        $component = Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.git_repository_url', 'https://example.com/jobs.git')
            ->call('detectFromRepository');

        $component->assertDontSeeHtml('wire:click="installDetectedRuntimeOnServer"');
    }

    public function test_install_missing_runtime_button_hidden_for_php_detection(): void
    {
        // PHP uses ondrej/php apt, not mise — even though Laravel/PHP isn't
        // in `runtime_defaults`, we don't surface the affordance because
        // the install path is fundamentally different and assumed already
        // configured by ServerProvisionCommandBuilder's PHP install step.
        [$user, $server] = $this->makeServerWithUser();

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
    }

    public function test_create_view_shows_auto_detect_panel_for_non_functions_host(): void
    {
        [$user, $server] = $this->makeServerWithUser();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSeeHtml('id="git_repository_url"')
            ->assertSeeHtml('wire:click="detectFromRepository"')
            ->assertSee('Auto-detect');
    }

    public function test_create_view_renders_plan_details_after_detection(): void
    {
        [$user, $server] = $this->makeServerWithUser();
        $this->fakeClonerThatProducesNodeRepoWithBullmq();

        $component = Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.git_repository_url', 'https://example.com/jobs.git')
            ->call('detectFromRepository');

        $component
            ->assertSee('node')
            ->assertSee('npm run build')
            ->assertSee('npm start')
            ->assertSee('confidence');
    }

    public function test_detect_from_repository_pre_fills_runtime_fields_from_plan(): void
    {
        [$user, $server] = $this->makeServerWithUser();
        $this->fakeClonerThatProducesNodeRepoWithBullmq();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.git_repository_url', 'https://example.com/jobs.git')
            ->set('form.git_branch', 'main')
            ->call('detectFromRepository')
            ->assertSet('form.runtime', 'node')
            ->assertSet('form.build_command', 'npm run build')
            ->assertSet('form.start_command', 'npm start')
            ->assertSet('form.type', 'node');
    }

    public function test_detect_from_repository_does_not_overwrite_user_edits(): void
    {
        [$user, $server] = $this->makeServerWithUser();
        $this->fakeClonerThatProducesNodeRepoWithBullmq();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            // User picks a runtime first, then runs detection.
            ->set('form.runtime', 'python')
            ->set('form.git_repository_url', 'https://example.com/jobs.git')
            ->call('detectFromRepository')
            ->assertSet('form.runtime', 'python');
    }

    public function test_detect_from_repository_records_clone_errors(): void
    {
        [$user, $server] = $this->makeServerWithUser();
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
        $this->assertSame('Repository not found.', $plan['error']);
    }

    public function test_store_persists_runtime_fields_for_node_site(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();

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
        $this->assertSame('node', $site->runtime);
        $this->assertSame('22.7.0', $site->runtime_version);
        $this->assertSame('npm run build', $site->build_command);
        $this->assertSame('npm start', $site->start_command);
        $this->assertSame('https://example.com/jobs.git', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertNotNull($site->internal_port);
        $this->assertGreaterThanOrEqual(30000, $site->internal_port);
        $this->assertLessThanOrEqual(39999, $site->internal_port);
    }

    public function test_store_does_not_allocate_internal_port_for_php_site(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'laravel-app')
            ->set('form.primary_hostname', 'laravel-app.example.com')
            ->set('form.runtime', 'php')
            ->set('form.type', 'php')
            ->set('form.php_version', '8.4')
            ->call('store');

        $site = Site::query()->where('name', 'laravel-app')->firstOrFail();
        $this->assertSame('php', $site->runtime);
        $this->assertNull($site->internal_port);
        $this->assertNull($site->start_command);
    }

    public function test_store_backfills_web_process_command_from_start_command(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();

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
        $this->assertNotNull($webProcess);
        $this->assertSame(
            'uvicorn main:app --host 0.0.0.0 --port 8000',
            $webProcess->command,
        );
    }

    public function test_store_materializes_detected_non_web_processes(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();

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
        $this->assertNotNull($worker);
        $this->assertSame('node worker.js', $worker->command);
        $this->assertSame(SiteProcess::TYPE_WORKER, $worker->type);
    }

    public function test_store_materializes_runtime_aware_default_deploy_steps(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();

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

        $this->assertCount(3, $steps);
        $commands = $steps->pluck('custom_command')->all();
        $this->assertContains('bundle install --deployment --without development:test', $commands);
        $this->assertContains('bundle exec rails assets:precompile', $commands);
        $this->assertContains('bundle exec rails db:migrate', $commands);
    }

    public function test_store_skips_default_steps_for_static_runtime(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.name', 'plain-static')
            ->set('form.primary_hostname', 'plain-static.example.com')
            ->set('form.runtime', 'static')
            ->set('form.type', 'static')
            ->call('store');

        $site = Site::query()->where('name', 'plain-static')->firstOrFail();
        $this->assertSame(0, $site->deploySteps()->count());
    }

    public function test_engine_picker_renders_only_for_multi_engine_servers(): void
    {
        [$user, $server] = $this->makeServerWithUser();
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
    }

    public function test_engine_picker_hidden_for_single_engine_server(): void
    {
        [$user, $server] = $this->makeServerWithUser();
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertDontSeeHtml('id="database_engine"');
    }

    public function test_create_form_loads_server_database_engines_and_picks_default(): void
    {
        [$user, $server] = $this->makeServerWithUser();
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
    }

    public function test_store_persists_null_engine_when_user_keeps_server_default(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();
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
        $this->assertNull($site->database_engine);
        // Accessor still returns 'postgres' via fallback.
        $this->assertSame('postgres', $site->databaseEngine());
    }

    public function test_store_persists_engine_override_when_user_picks_non_default(): void
    {
        Queue::fake();
        [$user, $server] = $this->makeServerWithUser();
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
        $this->assertSame('mysql84', $site->database_engine);
        $this->assertSame('mysql84', $site->databaseEngine());
    }

    /**
     * @return array{0: User, 1: Server}
     */
    private function makeServerWithUser(): array
    {
        $user = $this->userWithOrganization();
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

    private function fakeClonerThatProducesNodeRepoWithBullmq(): void
    {
        $this->app->instance(GitCloner::class, new class implements GitCloner
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
        unset($this->app[RepositoryRuntimePreview::class]);
    }
}
