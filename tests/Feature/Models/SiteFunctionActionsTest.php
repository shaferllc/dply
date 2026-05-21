<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\FunctionAction;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFunctionActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_site_exposes_its_function_actions_with_code_actions_first(): void
    {
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

        $this->assertCount(2, $actions);
        // Code actions sort ahead of sequences.
        $this->assertSame('worker', $actions->first()->name);
        $this->assertSame(FunctionAction::KIND_CODE, $actions->first()->kind);
        $this->assertSame('pipeline', $actions->last()->name);
    }

    public function test_function_actions_are_scoped_to_their_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        FunctionAction::query()->create(['site_id' => $siteA->id, 'name' => 'a', 'kind' => FunctionAction::KIND_CODE]);
        FunctionAction::query()->create(['site_id' => $siteB->id, 'name' => 'b', 'kind' => FunctionAction::KIND_CODE]);

        $this->assertSame(['a'], $siteA->functionActions()->pluck('name')->all());
    }
}
