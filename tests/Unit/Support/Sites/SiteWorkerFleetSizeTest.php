<?php

use App\Models\Server;
use App\Support\Sites\SiteWorkerFleetSize;

it('steps one size down the known DigitalOcean ladder', function () {
    $app = new Server(['size' => 's-2vcpu-4gb']);

    expect(SiteWorkerFleetSize::defaultFor($app))->toBe('s-2vcpu-2gb');
});

it('keeps the smallest known size', function () {
    $app = new Server(['size' => 's-1vcpu-1gb']);

    expect(SiteWorkerFleetSize::defaultFor($app))->toBe('s-1vcpu-512mb-10gb');
});

it('returns an unknown slug unchanged', function () {
    $app = new Server(['size' => 'custom-worker-box']);

    expect(SiteWorkerFleetSize::defaultFor($app))->toBe('custom-worker-box');
});
