<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\Config\EdgeRepoConfigLoader;
use App\Modules\Edge\Support\EdgeEffectiveAlerts;

uses()->group('edge');

test('loader normalizes alerts from dply.yaml', function () {
    $yaml = <<<'YAML'
alerts:
  lcp_p75_ms:
    enabled: true
    threshold: 2500
  error_rate:
    enabled: true
    threshold: 1.5
  five_xx_count:
    enabled: false
    threshold: 100
YAML;

    $config = (new EdgeRepoConfigLoader)->parse('dply.yaml', $yaml);

    expect($config->alerts)->toMatchArray([
        'lcp_p75_ms' => ['enabled' => true, 'threshold' => 2500],
        'error_rate' => ['enabled' => true, 'threshold' => 1.5],
        'five_xx_count' => ['enabled' => false, 'threshold' => 100],
    ])->and($config->warnings)->toBe([]);
});

test('loader warns on unknown alert metrics', function () {
    $yaml = <<<'YAML'
alerts:
  fancy_metric:
    enabled: true
    threshold: 1
YAML;

    $config = (new EdgeRepoConfigLoader)->parse('dply.yaml', $yaml);

    expect($config->alerts)->toBe([])
        ->and($config->warnings)->not->toBeEmpty();
});

test('effective alerts prefer dashboard over repo per metric', function () {
    $site = new Site;
    $site->mergeEdgeMeta([
        'alerts' => [
            'lcp_p75_ms' => ['enabled' => true, 'threshold' => 3000],
        ],
    ]);

    $deployment = new EdgeDeployment;
    $deployment->repo_config = [
        'alerts' => [
            'lcp_p75_ms' => ['enabled' => true, 'threshold' => 2000],
            'error_rate' => ['enabled' => true, 'threshold' => 2],
        ],
    ];

    $effective = EdgeEffectiveAlerts::for($site, $deployment);

    expect($effective['lcp_p75_ms'])->toMatchArray(['enabled' => true, 'threshold' => 3000])
        ->and($effective['error_rate'])->toMatchArray(['enabled' => true, 'threshold' => 2.0])
        ->and($effective['sources']['repo'])->toBeTrue()
        ->and($effective['sources']['dashboard'])->toBeTrue()
        ->and(EdgeEffectiveAlerts::anyEnabled($effective))->toBeTrue();
});
