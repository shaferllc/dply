<?php

declare(strict_types=1);

use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\ServerConsoleActionLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('console action lookup batches banner switch and inflight checks', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'manage_action',
        'status' => ConsoleAction::STATUS_RUNNING,
        'label' => 'Reloading nginx…',
        'output' => ['v' => 1, 'lines' => []],
    ]);

    ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'webserver_switch',
        'status' => ConsoleAction::STATUS_QUEUED,
        'label' => 'Switching webserver…',
        'output' => ['v' => 1, 'lines' => []],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $lookup = app(ServerConsoleActionLookup::class);
    $state = $lookup->stateFor($server);

    expect($lookup->hasInflightWebserverSwitch($server))->toBeTrue()
        ->and($lookup->hasInflightEdgeProxy($server))->toBeFalse()
        ->and($state['banner']?->kind)->toBe('manage_action')
        ->and($state['webserver_switch']?->kind)->toBe('webserver_switch')
        ->and($state['banner']?->relationLoaded('subject'))->toBeTrue();

    $consoleQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "console_actions"'))
        ->count();

    DB::disableQueryLog();

    expect($consoleQueries)->toBe(1);
});

test('console action lookup skips server refresh when banner is idle', function (): void {
    $server = Server::factory()->create();
    $lookup = app(ServerConsoleActionLookup::class);

    expect($lookup->shouldRefreshServerMeta($server))->toBeFalse();
});
