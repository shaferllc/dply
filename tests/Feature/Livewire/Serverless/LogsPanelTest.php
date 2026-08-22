<?php

namespace Tests\Feature\Livewire\Serverless\LogsPanelTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Livewire\LogsPanel;
use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site}
 */
function logsFixture(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);

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
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    return [$user, $site];
}

function makeActivations(Site $site, int $count): void
{
    foreach (range(1, $count) as $i) {
        FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => FunctionInvocation::SOURCE_TICK,
            'state' => FunctionInvocation::STATE_COMPLETED,
            'success' => true,
            'status_code' => 200,
            'duration_ms' => 10 + $i,
            'created_at' => now()->subMinutes($count - $i),
        ]);
    }
}

it('pages the activations list instead of truncating at 50', function () {
    [$user, $site] = logsFixture();
    makeActivations($site, 60);

    $page = Livewire::actingAs($user)->test(LogsPanel::class, ['site' => $site]);

    // 25 per page, and the tab badge reports the true total, not the page size.
    expect(substr_count($page->html(), 'data-invocation-row'))->toBe(25);
    $page->assertSee('60');

    // The 60th-oldest row is unreachable without paging — that was the bug.
    $page->call('gotoPage', 3, 'activationsPage');
    expect(substr_count($page->html(), 'data-invocation-row'))->toBe(10);
});

it('carries the shared CLI footer, per tab', function () {
    [$user, $site] = logsFixture();

    $page = Livewire::actingAs($user)->test(LogsPanel::class, ['site' => $site]);

    $page->assertSee("dply serverless invocations {$site->slug}")
        ->assertSee("dply serverless invoke {$site->slug}")
        ->assertSee("dply serverless errors {$site->slug}");

    $page->call('setTab', 'runtime')
        ->assertSee("dply serverless logs {$site->slug} --follow")
        ->assertSee("dply serverless logs {$site->slug} --level error --window 86400");
});

it('renders a row whose log lines were never written', function () {
    // Async rows exist before their result does; logLines() must read that as
    // "nothing yet", not blow up mid-list.
    [$user, $site] = logsFixture();

    FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_TICK,
        'state' => FunctionInvocation::STATE_PENDING,
        'log_lines' => null,
    ]);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->assertOk()
        ->assertSee('Running');
});
