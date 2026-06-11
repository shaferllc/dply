<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\NoHorizonSuggestionTest;

use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SitePipelineAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the advisor no longer suggests a horizon:terminate step (managed restart handles it)', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'laravel_horizon' => true,
                ],
            ],
        ],
    ]);

    $keys = collect(SitePipelineAdvisor::suggestions($site->fresh()))->pluck('key')->all();

    // The advisor still runs for this Laravel site (it suggests the missing
    // optimize/storage steps)…
    expect($keys)->toContain('optimize');
    // …but never the Horizon step anymore.
    expect($keys)->not->toContain('horizon');
});
