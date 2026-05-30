<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Support\Servers\SharedHostFairnessAdvisor;
use Laravel\Pennant\Feature;

test('fairness advisor recommends actions for dominant site event', function () {
    Feature::define('workspace.site_promote', fn () => true);

    $server = Server::factory()->make(['id' => '01TESTSERVER00000000000001', 'name' => 'Prod']);
    $site = Site::factory()->make([
        'id' => '01TESTSITE0000000000000001',
        'server_id' => $server->id,
        'slug' => 'api',
        'name' => 'API',
    ]);
    $server->setRelation('sites', collect([$site]));

    $report = [
        'solo_tenant' => false,
        'site_count' => 2,
        'overall' => 'warning',
        'contention_count' => 1,
        'contention_events' => [[
            'id' => 'dominant-api',
            'kind' => 'dominant_site',
            'severity' => 'warning',
            'title' => 'Noisy neighbor detected',
            'message' => 'API is using 80% of CPU.',
            'site_slug' => 'api',
            'site_name' => 'API',
        ]],
        'budget_breaches' => [],
        'shared_map' => ['shared_resources' => []],
        'summary' => ['dominant_site' => null],
    ];

    $advisor = app(SharedHostFairnessAdvisor::class)->advise($server, $report);

    expect($advisor['recommendations'])->not->toBeEmpty();
    expect($advisor['recommendations'][0]['actions'])->not->toBeEmpty();
    expect(collect($advisor['recommendations'][0]['actions'])->pluck('label')->all())
        ->toContain(__('Promote to standby'));
});

test('fairness advisor returns info summary for solo tenant', function () {
    $server = Server::factory()->make(['name' => 'Solo']);

    $advisor = app(SharedHostFairnessAdvisor::class)->advise($server, [
        'solo_tenant' => true,
        'site_count' => 1,
    ]);

    expect($advisor['recommendations'])->toBe([]);
    expect($advisor['severity'])->toBe('info');
});
