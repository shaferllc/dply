<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Preview;

use App\Models\Site;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('site label uses slug and stable id hash suffix', function () {
    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);
    $site->id = '01jtestsite0000000000000000';

    $label = app(UnifiedPreviewHostname::class)->siteLabel($site);

    expect($label)->toStartWith('marketing-api-');
    expect(strlen($label))->toBeLessThanOrEqual(63);
});

test('branch preview uses double dash qualifier on parent label', function () {
    $parent = new Site([
        'name' => 'Edge App',
        'slug' => 'edge-app',
    ]);
    $parent->id = '01jparent000000000000000000';

    $hostname = app(UnifiedPreviewHostname::class)->branchPreviewHostname($parent, 'feature/login', 7, 'on-dply.site');

    expect($hostname)->toMatch('/^edge-app-[a-f0-9]{8}--pr-7\.on-dply\.site$/');
});

test('ordered testing zones prefer on-dply apex when configured', function () {
    config([
        'preview.prefer_on_dply_apex' => true,
        'services.digitalocean.testing_domains' => ['dply.host', 'on-dply.site', 'dply.cc'],
        'edge.testing_domains' => ['on-dply.site'],
    ]);

    $ordered = app(UnifiedPreviewHostname::class)->orderedTestingZones([
        'dply.host',
        'on-dply.site',
        'dply.cc',
    ]);

    expect($ordered[0])->toBe('on-dply.site');
});

test('edge default hostname uses unified label when no routing hostname set', function () {
    config(['preview.unified_hostnames' => true, 'edge.testing_domains' => ['on-dply.site']]);

    $site = Site::factory()->make([
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => []],
    ]);
    $site->id = '01jedge0000000000000000000';

    $hostname = $site->edgeHostname();

    expect($hostname)->toEndWith('.on-dply.site');
    expect($hostname)->toContain('edge-app-');
});
