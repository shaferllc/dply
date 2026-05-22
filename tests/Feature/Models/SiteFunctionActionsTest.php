<?php

declare(strict_types=1);

namespace Tests\Feature\Models\SiteFunctionActionsTest;
use App\Models\FunctionAction;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('a site exposes its function actions with code actions first', function () {
    $site = Site::factory()->create();

    FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'pipeline',
        'kind' => FunctionAction::KIND_SEQUENCE,
    ]);
    FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'worker',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'nodejs:18',
    ]);

    $actions = $site->functionActions()->get();

    expect($actions)->toHaveCount(2);

    // Code actions sort ahead of sequences.
    expect($actions->first()->name)->toBe('worker');
    expect($actions->first()->kind)->toBe(FunctionAction::KIND_CODE);
    expect($actions->last()->name)->toBe('pipeline');
});
test('function actions are scoped to their site', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    FunctionAction::query()->create(['site_id' => $siteA->id, 'name' => 'a', 'kind' => FunctionAction::KIND_CODE]);
    FunctionAction::query()->create(['site_id' => $siteB->id, 'name' => 'b', 'kind' => FunctionAction::KIND_CODE]);

    expect($siteA->functionActions()->pluck('name')->all())->toBe(['a']);
});
