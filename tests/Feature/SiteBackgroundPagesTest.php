<?php

declare(strict_types=1);

namespace Tests\Feature\SiteBackgroundPagesTest;

use App\Livewire\Sites\Schedule;
use App\Livewire\Sites\Workers;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
| Every site here is a functions site, so two things apply throughout:
|
|   1. RedirectServerlessByoWorkspace funnels the BYO
|      /servers/{server}/sites/{site}/… URLs onto /serverless/{site}/… . These
|      tests assert "the page renders", not which URL serves it, so they follow
|      the redirect rather than restating each product URL.
|   2. Those product routes sit behind `feature:surface.serverless`, which
|      aborts 400 (Pennant's EnsureFeaturesAreActive) when inactive.
*/
usesFeatures('surface.serverless');

function actingOrgOwner(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
function makeFunctionsSite(User $user): array
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
test('schedule route renders', function () {
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $this->actingAs($user)
        ->followingRedirects()->get(route('sites.schedule', [$server, $site]))
        ->assertOk()
        ->assertSee('Schedule')
        ->assertSee('Run the scheduler every minute');
});
test('workers route renders', function () {
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $this->actingAs($user)
        ->followingRedirects()->get(route('sites.workers', [$server, $site]))
        ->assertOk()
        ->assertSee('Workers')
        // Heading trimmed to "Process queue jobs".
        ->assertSee('Process queue jobs');
});
test('schedule toggle flips scheduler independently', function () {
    // Schedule and Workers are now independent. Turning the scheduler on
    // must NOT silently enable the queue worker. The legacy bundled flag
    // (`background_enabled`) is kept in sync — true iff either dedicated
    // flag is on — for any caller that still reads the old key.
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    Livewire::actingAs($user)
        ->test(Schedule::class, ['server' => $server, 'site' => $site])
        ->set('scheduler_enabled', true)
        ->assertHasNoErrors();

    $site->refresh();
    $serverless = $site->meta['serverless'] ?? [];
    expect((bool) ($serverless['scheduler_enabled'] ?? false))->toBeTrue();
    expect((bool) ($serverless['queue_worker_enabled'] ?? false))->toBeFalse('Enabling the scheduler should not flip the queue worker on.');
    expect((bool) ($serverless['background_enabled'] ?? false))->toBeTrue('Legacy bundled flag stays in sync with "either is on".');
});
test('workers toggle flips queue worker independently', function () {
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    Livewire::actingAs($user)
        ->test(Workers::class, ['server' => $server, 'site' => $site])
        ->set('queue_worker_enabled', true)
        ->assertHasNoErrors();

    $site->refresh();
    $serverless = $site->meta['serverless'] ?? [];
    expect((bool) ($serverless['queue_worker_enabled'] ?? false))->toBeTrue();
    expect((bool) ($serverless['scheduler_enabled'] ?? false))->toBeFalse('Enabling the queue worker should not flip the scheduler on.');
    expect((bool) ($serverless['background_enabled'] ?? false))->toBeTrue();
});
test('disabling one task keeps the other', function () {
    // Start with both on; disable scheduler; queue worker must remain on
    // and the bundled flag must stay true (because queue is still ticking).
    $user = actingOrgOwner();
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
    expect((bool) ($serverless['scheduler_enabled'] ?? false))->toBeFalse();
    expect((bool) ($serverless['queue_worker_enabled'] ?? false))->toBeTrue('Queue worker stays on when only the scheduler is disabled.');
    expect((bool) ($serverless['background_enabled'] ?? false))->toBeTrue('Bundled flag stays true because the queue worker is still on.');
});
test('environment section shows variables workspace', function () {
    // Serverless Environment is the variables editor (bindings live on the
    // VM Resources hub). Confirm the section still renders for functions sites.
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $response = $this->actingAs($user)->followingRedirects()->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
        'section' => 'environment',
    ], false));

    $response->assertOk()
        ->assertSee('Environment');
});
test('every sidebar item renders 200 for serverless site', function () {
    // Sidebar QA guard — walk every sidebar item for a baseline serverless
    // site and assert each lands on a 200. Catches blade compile errors,
    // missing routes, missing Livewire methods, and any other "click that
    // breaks the workspace" regressions before the operator hits them.
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $items = SiteSettingsSidebar::items($site, $server);
    expect($items)->not->toBeEmpty('Sidebar should have items for a serverless site');
    expect(collect($items)->pluck('id'))->not->toContain('resources');

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

        $response = $this->actingAs($user)->followingRedirects()->get($url);
        expect($response->status())->toBe(200, "Sidebar item [{$id}] at {$url} returned HTTP {$response->status()}");
    }
});
test('section repository url renders the repository workspace', function () {
    // Repository for serverless workspaces is now a dedicated Livewire
    // page (tabbed: Overview / Files / Branches / Connection) — the
    // sidebar item points at `sites.repository`, and the bare
    // `/repository` URL resolves there directly (the path route is
    // registered before the wildcard `sites.show` dispatcher).
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    // The Overview/Files/Branches/Connection tab strip only renders once a repo
    // is connected — without git_repository_url the page shows the "connect a
    // repository" empty state instead.
    $site->forceFill(['git_repository_url' => 'https://github.com/acme/laravel-app.git'])->save();

    $repositoryResponse = $this->actingAs($user)->followingRedirects()->get(route('sites.repository', [
        'server' => $server,
        'site' => $site,
    ], false));

    $repositoryResponse->assertOk()
        ->assertSee('Repository')
        ->assertSee('Overview')
        ->assertSee('Files')
        ->assertSee('Branches')
        ->assertSee('Connection');
});
test('sidebar deployments item routes to history list', function () {
    // Per Q3/Q12, the "Deployments" sidebar item leads to the history
    // list at sites.deployments.index — NOT to the deploy config recipe
    // (which now lives on Repository).
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

    expect($items['deploy']['route'] ?? null)->toBe('sites.deployments.index');

    $response = $this->actingAs($user)->followingRedirects()->get(route('sites.deployments.index', [$server, $site]));
    $response->assertOk()->assertSee('Deployments');
});

test('vm sidebar deploy group routes to deployments repository and pipeline', function () {
    $user = actingOrgOwner();
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

    $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

    expect($items['deploy']['route'] ?? null)->toBe('sites.deployments.index')
        ->and($items->has('pipeline'))->toBeFalse()
        ->and($items->has('repository'))->toBeTrue()
        ->and($items['repository']['route'] ?? null)->toBe('sites.repository');
});
test('clicking a history row opens the tick detail modal with the full body', function () {
    // The history table truncates the body to 120 chars; the detail modal
    // shows the whole captured preview. The marker sits past char 120 on
    // an *older* row (the latest row's full body already shows in the
    // "Latest output" panel) so only the modal can surface it.
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $olderBody = str_repeat('A', 200).'OLD-TAIL-MARKER';
    $older = FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_TICK,
        'task' => 'schedule',
        'method' => 'GET',
        'path' => '/',
        'status_code' => 500,
        'success' => false,
        'duration_ms' => 5466,
        'cold' => false,
        'activation_id' => 'act-old',
        'log_lines' => [],
        'result_excerpt' => $olderBody,
        'created_at' => now()->subMinute(),
    ]);
    FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_TICK,
        'task' => 'schedule',
        'method' => 'GET',
        'path' => '/',
        'status_code' => 200,
        'success' => true,
        'duration_ms' => 42,
        'cold' => false,
        'activation_id' => 'act-new',
        'log_lines' => [],
        'result_excerpt' => 'most recent tick',
        'created_at' => now(),
    ]);
    $olderAt = $older->refresh()->created_at->toIso8601String();

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
});
test('workers page can add a named worker', function () {
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

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
    expect($workers)->toHaveCount(1);
    expect($workers[0]['name'])->toBe('queue-default');
    expect($workers[0]['command'])->toBe('php artisan queue:work');
    expect($workers[0]['concurrency'])->toBe(3);
    expect($workers[0]['restart_policy'])->toBe('always');
    expect($workers[0]['enabled'])->toBeTrue();
});
test('adding a worker validates required fields', function () {
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    Livewire::actingAs($user)
        ->test(Workers::class, ['server' => $server, 'site' => $site])
        ->call('newWorker')
        ->set('workerName', '')
        ->set('workerCommand', '')
        ->call('saveWorker')
        ->assertHasErrors(['workerName', 'workerCommand']);

    expect($site->refresh()->meta['serverless']['workers'] ?? [])->toBe([]);
});
test('workers page can toggle and remove a worker', function () {
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);
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

    expect($site->refresh()->meta['serverless']['workers'][0]['enabled'])->toBeFalse();

    $component->call('deleteWorker', 'wkr-1');

    expect($site->refresh()->meta['serverless']['workers'] ?? [])->toBe([]);
});
test('workers page shows dns provisioning failure banner', function () {
    $user = actingOrgOwner();
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
        ->followingRedirects()->get(route('sites.workers', [$server, $site]))
        ->assertOk()
        ->assertSee('DNS provisioning failed')
        ->assertSee('CNAME records cannot share a name with other records')
        // Remediation copy now names what to check, not where to look.
        ->assertSee('Check the zone/token in DigitalOcean, then retry.');
});
test('schedule wears the same merged chrome as workers', function () {
    // The pair are twins: one outer card with hairline strips, no stacked
    // floating cards and no hero. Schedule drifted back to stacked cards
    // once; this pins it to whatever Workers does.
    $user = actingOrgOwner();
    [$server, $site] = makeFunctionsSite($user);

    $html = fn (string $component): string => Livewire::actingAs($user)
        ->test($component, ['server' => $server, 'site' => $site])
        ->html();

    $schedule = $html(Schedule::class);
    $workers = $html(Workers::class);

    expect(substr_count($schedule, 'dply-card'))
        ->toBe(substr_count($workers, 'dply-card'), 'Schedule should render the same number of cards as Workers.')
        ->and($schedule)->not->toContain('data-hero-card')
        // `space-y-6` between sections is the stacked-card layout it left.
        ->and($schedule)->not->toContain('space-y-6');
});
