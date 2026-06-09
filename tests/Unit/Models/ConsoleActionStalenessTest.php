<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ConsoleAction;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('queued console action is stale after queued stall threshold', function (): void {
    config(['console_actions.queued_stalled_after_seconds' => 30]);

    $site = Site::factory()->create();

    $action = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'webserver_config',
        'status' => ConsoleAction::STATUS_QUEUED,
        'output' => ['v' => 1, 'lines' => []],
    ]);
    ConsoleAction::query()->whereKey($action->id)->update([
        'created_at' => now()->subSeconds(31),
    ]);
    $action->refresh();

    expect($action->isQueuedStalled())->toBeTrue()
        ->and($action->isStale())->toBeTrue();
});

test('running console action uses longer running stale threshold', function (): void {
    config([
        'console_actions.queued_stalled_after_seconds' => 30,
        'console_actions.stale_after_seconds' => 600,
    ]);

    $site = Site::factory()->create();

    $action = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'webserver_config',
        'status' => ConsoleAction::STATUS_RUNNING,
        'started_at' => now()->subSeconds(120),
        'created_at' => now()->subSeconds(120),
        'output' => ['v' => 1, 'lines' => []],
    ]);

    expect($action->isQueuedStalled())->toBeFalse()
        ->and($action->isStale())->toBeFalse();
});
