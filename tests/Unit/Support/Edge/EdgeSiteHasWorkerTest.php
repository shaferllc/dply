<?php

declare(strict_types=1);

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeSiteHasWorker;

function makeEdgeSiteForWorkerCheck(string $runtimeMode = 'static'): Site
{
    $site = new Site;
    $site->edge_backend = 'dply_edge';
    $site->meta = [
        'runtime_profile' => 'edge_web',
        'edge' => [
            'runtime_mode' => $runtimeMode,
        ],
    ];

    return $site;
}

test('ssr runtime mode has a worker', function () {
    expect(EdgeSiteHasWorker::for(makeEdgeSiteForWorkerCheck('ssr')))->toBeTrue();
});

test('static runtime without middleware has no worker', function () {
    expect(EdgeSiteHasWorker::for(makeEdgeSiteForWorkerCheck('static')))->toBeFalse();
});

test('hybrid runtime without middleware has no worker', function () {
    expect(EdgeSiteHasWorker::for(makeEdgeSiteForWorkerCheck('hybrid')))->toBeFalse();
});

test('middleware script on deployment counts as a worker', function () {
    $site = makeEdgeSiteForWorkerCheck('static');
    $deployment = new EdgeDeployment;
    $deployment->meta = [
        'middleware' => [
            'script_name' => 'dply-mw-site-deploy',
        ],
    ];

    expect(EdgeSiteHasWorker::for($site, $deployment))->toBeTrue();
});

test('empty middleware script name does not count as a worker', function () {
    $site = makeEdgeSiteForWorkerCheck('static');
    $deployment = new EdgeDeployment;
    $deployment->meta = [
        'middleware' => [
            'script_name' => '   ',
        ],
    ];

    expect(EdgeSiteHasWorker::for($site, $deployment))->toBeFalse();
});
