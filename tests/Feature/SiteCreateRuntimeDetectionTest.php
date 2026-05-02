<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Sites\Create as SitesCreate;
use App\Models\Organization;
use App\Models\Server;
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

    /**
     * @return array{0: \App\Models\User, 1: Server}
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
