<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\PipelineAdvisorRuntimeBlockerTest;

use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SitePipelineAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function laravelSiteNeedingPhp84(): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'version' => '^8.4',
                ],
            ],
        ],
    ]);
}

test('a php upgrade hides migrate optimize and storage suggestions', function () {
    $site = laravelSiteNeedingPhp84();

    $keys = collect(SitePipelineAdvisor::suggestions($site->fresh()))->pluck('key')->all();

    expect($keys)->toBe(['upgrade_php'])
        ->and(SitePipelineAdvisor::hasBlockingRuntimeSuggestion(
            SitePipelineAdvisor::suggestions($site->fresh()),
        ))->toBeTrue();
});

test('autofix lookup still sees the hidden pipeline steps', function () {
    $site = laravelSiteNeedingPhp84();

    $keys = collect(SitePipelineAdvisor::suggestions($site->fresh(), true))->pluck('key')->all();

    expect($keys)->toContain('upgrade_php')
        ->and($keys)->toContain('migrate')
        ->and($keys)->toContain('optimize')
        ->and($keys)->toContain('storage_link');
});

test('dismissing the php upgrade reveals the other pipeline suggestions', function () {
    $site = laravelSiteNeedingPhp84();
    $site->forceFill([
        'meta' => array_merge($site->meta ?? [], [
            SitePipelineAdvisor::DISMISSED_META_KEY => ['upgrade_php'],
        ]),
    ])->save();

    $keys = collect(SitePipelineAdvisor::suggestions($site->fresh()))->pluck('key')->all();

    expect($keys)->not->toContain('upgrade_php')
        ->and($keys)->toContain('migrate')
        ->and($keys)->toContain('optimize');
});
