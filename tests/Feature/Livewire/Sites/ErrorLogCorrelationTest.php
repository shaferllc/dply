<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\ErrorLogCorrelationTest;

use App\Livewire\Sites\Errors;
use App\Models\ErrorEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerLogAgent;
use App\Models\Site;
use App\Models\User;
use App\Modules\Logs\Services\ClickHouseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

/**
 * The site Errors view jumps from a failure into the dply Logs slice around it,
 * via the shared CorrelatesErrorLogs concern + drawer. The jump is site-scoped:
 * another tenant's error id must never open a drawer.
 */
function fakeClickHouseReturning(array $rows): void
{
    $ch = Mockery::mock(ClickHouseClient::class);
    $ch->shouldReceive('qualifiedTable')->andReturn('dply_logs.server_logs');
    $ch->shouldReceive('select')->andReturn($rows);
    app()->instance(ClickHouseClient::class, $ch);
}

/** @return array{0: User, 1: Server, 2: Site} */
function ownedSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    return [$user, $server, $site];
}

function makeSiteError(Server $server, Site $site): ErrorEvent
{
    return ErrorEvent::query()->create([
        'organization_id' => $site->organization_id,
        'server_id' => $server->id,
        'site_id' => $site->id,
        'source_type' => 'http_5xx',
        'source_id' => 'ref-'.$site->id,
        'category' => 'http_5xx',
        'reference' => 'ABC123XYZ',
        'title' => 'Server Error',
        'occurred_at' => Carbon::parse('2026-06-17 12:00:00', 'UTC'),
    ]);
}

test('openLogsForError opens the drawer with the correlated log slice', function () {
    fakeClickHouseReturning([
        ['timestamp' => '2026-06-17 12:00:01', 'level' => 'error', 'source' => 'web', 'message' => 'boom'],
    ]);
    [$user, $server, $site] = ownedSite();
    $error = makeSiteError($server, $site);

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->call('openLogsForError', $error->id)
        ->assertSet('errorLogsOpen', true)
        ->assertSet('errorLogsResult.logs.0.message', 'boom');
});

test('openLogsForError refuses another tenant’s error id (no drawer)', function () {
    fakeClickHouseReturning([['timestamp' => 'x', 'message' => 'leak']]);
    [$user, $server, $site] = ownedSite();

    // A foreign tenant's error — identical shape, different site.
    $otherUser = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    $otherOrg->users()->attach($otherUser->id, ['role' => 'owner']);
    $foreignServer = Server::factory()->create(['organization_id' => $otherOrg->id, 'user_id' => $otherUser->id]);
    $foreignSite = Site::factory()->create([
        'server_id' => $foreignServer->id,
        'organization_id' => $otherOrg->id,
        'user_id' => $otherUser->id,
    ]);
    $foreignError = makeSiteError($foreignServer, $foreignSite);

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->call('openLogsForError', $foreignError->id)
        ->assertSet('errorLogsOpen', false);
});

test('showLogCorrelation gating follows whether the server ships logs', function () {
    [$user, $server, $site] = ownedSite();

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->assertSet('showLogCorrelation', false);

    $server->logAgent()->create(['status' => ServerLogAgent::STATUS_RUNNING]);

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->assertSet('showLogCorrelation', true);
});
