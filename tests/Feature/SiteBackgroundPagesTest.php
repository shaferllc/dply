<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Sites\Schedule;
use App\Livewire\Sites\Workers;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteBackgroundPagesTest extends TestCase
{
    use RefreshDatabase;

    private function actingOrgOwner(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    private function makeFunctionsSite(User $user): array
    {
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => ['background_enabled' => false],
            ],
        ]);

        return [$server, $site];
    }

    public function test_schedule_route_renders(): void
    {
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $this->actingAs($user)
            ->get(route('sites.schedule', [$server, $site]))
            ->assertOk()
            ->assertSee('Schedule')
            ->assertSee('Run the scheduler every minute');
    }

    public function test_workers_route_renders(): void
    {
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $this->actingAs($user)
            ->get(route('sites.workers', [$server, $site]))
            ->assertOk()
            ->assertSee('Workers')
            ->assertSee('Process queue jobs in background ticks');
    }

    public function test_schedule_toggle_flips_scheduler_independently(): void
    {
        // Schedule and Workers are now independent. Turning the scheduler on
        // must NOT silently enable the queue worker. The legacy bundled flag
        // (`background_enabled`) is kept in sync — true iff either dedicated
        // flag is on — for any caller that still reads the old key.
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        Livewire::actingAs($user)
            ->test(Schedule::class, ['server' => $server, 'site' => $site])
            ->set('scheduler_enabled', true)
            ->assertHasNoErrors();

        $site->refresh();
        $serverless = $site->meta['serverless'] ?? [];
        $this->assertTrue((bool) ($serverless['scheduler_enabled'] ?? false));
        $this->assertFalse((bool) ($serverless['queue_worker_enabled'] ?? false), 'Enabling the scheduler should not flip the queue worker on.');
        $this->assertTrue((bool) ($serverless['background_enabled'] ?? false), 'Legacy bundled flag stays in sync with "either is on".');
    }

    public function test_workers_toggle_flips_queue_worker_independently(): void
    {
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        Livewire::actingAs($user)
            ->test(Workers::class, ['server' => $server, 'site' => $site])
            ->set('queue_worker_enabled', true)
            ->assertHasNoErrors();

        $site->refresh();
        $serverless = $site->meta['serverless'] ?? [];
        $this->assertTrue((bool) ($serverless['queue_worker_enabled'] ?? false));
        $this->assertFalse((bool) ($serverless['scheduler_enabled'] ?? false), 'Enabling the queue worker should not flip the scheduler on.');
        $this->assertTrue((bool) ($serverless['background_enabled'] ?? false));
    }

    public function test_disabling_one_task_keeps_the_other(): void
    {
        // Start with both on; disable scheduler; queue worker must remain on
        // and the bundled flag must stay true (because queue is still ticking).
        $user = $this->actingOrgOwner();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => [
                    'scheduler_enabled' => true,
                    'queue_worker_enabled' => true,
                    'background_enabled' => true,
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(Schedule::class, ['server' => $server, 'site' => $site])
            ->set('scheduler_enabled', false)
            ->assertHasNoErrors();

        $site->refresh();
        $serverless = $site->meta['serverless'] ?? [];
        $this->assertFalse((bool) ($serverless['scheduler_enabled'] ?? false));
        $this->assertTrue((bool) ($serverless['queue_worker_enabled'] ?? false), 'Queue worker stays on when only the scheduler is disabled.');
        $this->assertTrue((bool) ($serverless['background_enabled'] ?? false), 'Bundled flag stays true because the queue worker is still on.');
    }

    public function test_environment_section_shows_bindings_sub_section(): void
    {
        // Q5: Environment page has three sub-sections — Variables / Secrets /
        // Bindings. The Bindings panel reads from SiteResourceBindingResolver
        // and lists managed-resource attachments (Database / Redis / Queue /
        // Object storage / etc.) with status badges. Attach / provision UI
        // is post-v1; this test confirms the read-only panel is in place.
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
            'section' => 'environment',
        ], false));

        $response->assertOk()
            ->assertSee('Bindings')
            ->assertSee('Database')
            ->assertSee('Redis')
            ->assertSee('Queue')
            ->assertSee('Object storage');
    }

    public function test_every_sidebar_item_renders_200_for_serverless_site(): void
    {
        // Sidebar QA guard — walk every sidebar item for a baseline serverless
        // site and assert each lands on a 200. Catches blade compile errors,
        // missing routes, missing Livewire methods, and any other "click that
        // breaks the workspace" regressions before the operator hits them.
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $items = SiteSettingsSidebar::items($site, $server);
        $this->assertNotEmpty($items, 'Sidebar should have items for a serverless site');

        foreach ($items as $item) {
            $id = $item['id'] ?? 'unknown';

            if (! empty($item['route'] ?? null)) {
                $routeArgs = ($item['route_params'] ?? null) === 'server_only'
                    ? ['server' => $server]
                    : ['server' => $server, 'site' => $site];
                $url = route($item['route'], $routeArgs, false);
            } else {
                $url = route('sites.show', [
                    'server' => $server,
                    'site' => $site,
                    'section' => $id,
                ], false);
            }

            $response = $this->actingAs($user)->get($url);
            $this->assertSame(200, $response->status(), "Sidebar item [{$id}] at {$url} returned HTTP {$response->status()}");
        }
    }

    public function test_section_repository_url_renders_the_repository_workspace(): void
    {
        // Repository for serverless workspaces is now a dedicated Livewire
        // page (tabbed: Overview / Files / Branches / Connection) — the
        // sidebar item points at `sites.repository`, and the bare
        // `/repository` URL resolves there directly (the path route is
        // registered before the wildcard `sites.show` dispatcher).
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $repositoryResponse = $this->actingAs($user)->get(route('sites.repository', [
            'server' => $server,
            'site' => $site,
        ], false));

        $repositoryResponse->assertOk()
            ->assertSee('Repository')
            ->assertSee('Overview')
            ->assertSee('Files')
            ->assertSee('Branches')
            ->assertSee('Connection');
    }

    public function test_sidebar_deployments_item_routes_to_history_list(): void
    {
        // Per Q3/Q12, the "Deployments" sidebar item leads to the history
        // list at sites.deployments.index — NOT to the deploy config recipe
        // (which now lives on Repository).
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

        $this->assertSame('sites.deployments.index', $items['deploy']['route'] ?? null);

        $response = $this->actingAs($user)->get(route('sites.deployments.index', [$server, $site]));
        $response->assertOk()->assertSee('Deployments');
    }

    public function test_clicking_a_history_row_opens_the_tick_detail_modal_with_the_full_body(): void
    {
        // The history table truncates the body to 120 chars; the detail modal
        // shows the whole captured preview. The marker sits past char 120 on
        // an *older* row (the latest row's full body already shows in the
        // "Latest output" panel) so only the modal can surface it.
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        $olderAt = now()->subMinute()->toIso8601String();
        $olderBody = str_repeat('A', 200).'OLD-TAIL-MARKER';
        $site->forceFill(['meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => [
                'scheduler_enabled' => true,
                'tick_history' => [
                    [
                        'at' => $olderAt,
                        'task' => 'schedule',
                        'status' => 'failed',
                        'http_status' => 500,
                        'duration_ms' => 5466,
                        'body_preview' => $olderBody,
                        'error' => null,
                    ],
                    [
                        'at' => now()->toIso8601String(),
                        'task' => 'schedule',
                        'status' => 'ok',
                        'http_status' => 200,
                        'duration_ms' => 42,
                        'body_preview' => 'most recent tick',
                        'error' => null,
                    ],
                ],
            ],
        ]])->save();

        Livewire::actingAs($user)
            ->test(Schedule::class, ['server' => $server, 'site' => $site])
            ->assertDontSee('Tick detail')
            ->assertDontSee('OLD-TAIL-MARKER')
            ->call('showTick', $olderAt)
            ->assertSee('Tick detail')
            ->assertSee('OLD-TAIL-MARKER')
            ->call('closeTick')
            ->assertDontSee('Tick detail')
            ->assertDontSee('OLD-TAIL-MARKER');
    }

    public function test_workers_page_can_add_a_named_worker(): void
    {
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        Livewire::actingAs($user)
            ->test(Workers::class, ['server' => $server, 'site' => $site])
            ->call('newWorker')
            ->assertSet('showWorkerForm', true)
            ->set('workerName', 'queue-default')
            ->set('workerCommand', 'php artisan queue:work')
            ->set('workerConcurrency', 3)
            ->set('workerRestartPolicy', 'always')
            ->call('saveWorker')
            ->assertHasNoErrors()
            ->assertSet('showWorkerForm', false);

        $workers = $site->refresh()->meta['serverless']['workers'] ?? [];
        $this->assertCount(1, $workers);
        $this->assertSame('queue-default', $workers[0]['name']);
        $this->assertSame('php artisan queue:work', $workers[0]['command']);
        $this->assertSame(3, $workers[0]['concurrency']);
        $this->assertSame('always', $workers[0]['restart_policy']);
        $this->assertTrue($workers[0]['enabled']);
    }

    public function test_adding_a_worker_validates_required_fields(): void
    {
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);

        Livewire::actingAs($user)
            ->test(Workers::class, ['server' => $server, 'site' => $site])
            ->call('newWorker')
            ->set('workerName', '')
            ->set('workerCommand', '')
            ->call('saveWorker')
            ->assertHasErrors(['workerName', 'workerCommand']);

        $this->assertSame([], $site->refresh()->meta['serverless']['workers'] ?? []);
    }

    public function test_workers_page_can_toggle_and_remove_a_worker(): void
    {
        $user = $this->actingOrgOwner();
        [$server, $site] = $this->makeFunctionsSite($user);
        $site->forceFill(['meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => [
                'background_enabled' => false,
                'workers' => [[
                    'id' => 'wkr-1',
                    'name' => 'queue-default',
                    'command' => 'php artisan queue:work',
                    'concurrency' => 1,
                    'restart_policy' => 'on-failure',
                    'enabled' => true,
                ]],
            ],
        ]])->save();

        $component = Livewire::actingAs($user)
            ->test(Workers::class, ['server' => $server, 'site' => $site])
            ->call('toggleWorker', 'wkr-1');

        $this->assertFalse($site->refresh()->meta['serverless']['workers'][0]['enabled']);

        $component->call('deleteWorker', 'wkr-1');

        $this->assertSame([], $site->refresh()->meta['serverless']['workers'] ?? []);
    }

    public function test_workers_page_shows_dns_provisioning_failure_banner(): void
    {
        $user = $this->actingOrgOwner();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => [
                    'background_enabled' => false,
                    'dns' => [
                        'status' => 'failed',
                        'hostname' => 'laravel-demo.dply.host',
                        'error' => 'DigitalOcean API failed to create domain record: CNAME records cannot share a name with other records',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('sites.workers', [$server, $site]))
            ->assertOk()
            ->assertSee('DNS provisioning failed')
            ->assertSee('CNAME records cannot share a name with other records')
            ->assertSee('Verify in the DigitalOcean dashboard, then retry');
    }
}
