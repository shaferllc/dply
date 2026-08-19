<?php

declare(strict_types=1);

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('multiline output is stored as one row per line with the same source', function () {
    $site = Site::factory()->create();
    $run = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'site_remediate',
        'status' => ConsoleAction::STATUS_RUNNING,
        'label' => 'Install the Postgres client (psql)',
        'output' => ['v' => 1, 'lines' => []],
    ]);

    (new ConsoleEmitter((string) $run->id))->step('fix', "Reading package lists...\nBuilding dependency tree...\n0 upgraded, 3 newly installed.");

    $lines = $run->fresh()?->lines() ?? [];

    expect($lines)->toHaveCount(3)
        ->and(collect($lines)->pluck('source')->unique()->all())->toBe(['fix'])
        ->and(collect($lines)->pluck('line')->all())->toBe([
            'Reading package lists...',
            'Building dependency tree...',
            '0 upgraded, 3 newly installed.',
        ]);
});

test('legacy multiline blobs still render as one tagged line each', function () {
    $site = Site::factory()->create();
    $run = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'site_remediate',
        'status' => ConsoleAction::STATUS_COMPLETED,
        'label' => 'Install the Postgres client (psql)',
        'output' => [
            'v' => 1,
            'lines' => [
                [
                    't' => 1,
                    'level' => 'step',
                    'source' => 'fix',
                    'line' => "Reading package lists...\nBuilding dependency tree...",
                ],
            ],
        ],
    ]);

    expect($run->lines())->toHaveCount(2)
        ->and(collect($run->lines())->pluck('source')->unique()->all())->toBe(['fix'])
        ->and(collect($run->lines())->pluck('line')->all())->toBe([
            'Reading package lists...',
            'Building dependency tree...',
        ]);
});
