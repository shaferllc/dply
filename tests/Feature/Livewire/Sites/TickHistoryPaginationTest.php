<?php

namespace Tests\Feature\Livewire\Sites\TickHistoryPaginationTest;

use App\Livewire\Sites\Schedule;
use App\Livewire\Sites\Workers;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function scheduleFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);
    session(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => ['scheduler_enabled' => true],
        ],
    ]);

    return [$user, $server, $site];
}

function makeTicks(Site $site, int $count, string $task = 'schedule'): void
{
    foreach (range(1, $count) as $index) {
        FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => FunctionInvocation::SOURCE_TICK,
            'task' => $task,
            'status_code' => 200,
            'success' => true,
            'duration_ms' => $index,
            'result_excerpt' => "tick-{$index}",
            'created_at' => now()->subMinutes($index),
        ]);
    }
}

it('pages the firing history instead of dumping every tick', function () {
    [$user, $server, $site] = scheduleFixture();
    makeTicks($site, 18);

    $component = Livewire::actingAs($user)->test(Schedule::class, ['server' => $server, 'site' => $site]);

    // 15 per page: the newest 15 render, the oldest three wait on page 2.
    $component->assertSee('tick-1')
        ->assertSee('tick-15')
        ->assertDontSee('tick-16')
        // The compact pager reports position, not a row of numbered links.
        ->assertSee('1 / 2');

    $component->call('gotoPage', 2, 'tickPage')
        ->assertSee('tick-16')
        ->assertSee('tick-18')
        ->assertDontSee('tick-1</td>');
});

it('opens a tick from any page, not just the one on screen', function () {
    [$user, $server, $site] = scheduleFixture();
    makeTicks($site, 18);

    // The oldest tick is on page 2; the detail modal must still resolve it
    // while page 1 is the one rendered.
    $oldest = FunctionInvocation::query()->oldest('created_at')->first();

    Livewire::actingAs($user)
        ->test(Schedule::class, ['server' => $server, 'site' => $site])
        ->call('showTick', $oldest->created_at->toIso8601String())
        ->assertSet('selectedTick.body_preview', 'tick-18');
});

it('pages the workers firing history the same way', function () {
    [$user, $server, $site] = scheduleFixture();
    makeTicks($site, 18, 'queue');

    $component = Livewire::actingAs($user)->test(Workers::class, ['server' => $server, 'site' => $site]);

    $component->assertSee('tick-15')
        ->assertDontSee('tick-16')
        ->assertSee('1 / 2');

    $component->call('gotoPage', 2, 'tickPage')
        ->assertSee('tick-18');
});

it('keeps the latest-output panel on the newest tick while paging', function () {
    [$user, $server, $site] = scheduleFixture();
    makeTicks($site, 18, 'queue');

    // The newest tick lives on page 1; the panel above the table describes it
    // and must keep doing so once the operator pages back through history.
    FunctionInvocation::query()->latest('created_at')->first()
        ->update(['result_excerpt' => 'NEWEST-TICK']);

    Livewire::actingAs($user)
        ->test(Workers::class, ['server' => $server, 'site' => $site])
        ->call('gotoPage', 2, 'tickPage')
        ->assertSee('NEWEST-TICK')
        ->assertSee('tick-18');
});
