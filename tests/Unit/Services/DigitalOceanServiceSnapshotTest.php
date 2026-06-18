<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanServiceSnapshotTest;

use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;

test('power off droplet returns action payload', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/42/actions' => Http::response([
            'action' => [
                'id' => 7001,
                'status' => 'in-progress',
                'type' => 'power_off',
            ],
        ], 201),
    ]);

    $svc = new DigitalOceanService('tok');
    $action = $svc->powerOffDroplet(42);

    expect($action['id'])->toBe(7001);
    expect($action['status'])->toBe('in-progress');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/droplets/42/actions')
            && $request->data() === ['type' => 'power_off'];
    });
});
test('snapshot droplet sends name in body', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/99/actions' => Http::response([
            'action' => ['id' => 8002, 'status' => 'in-progress', 'type' => 'snapshot'],
        ], 201),
    ]);

    $svc = new DigitalOceanService('tok');
    $action = $svc->snapshotDroplet(99, 'dply-base-test');

    expect($action['id'])->toBe(8002);
    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->data() === ['type' => 'snapshot', 'name' => 'dply-base-test'];
    });
});
test('snapshot droplet rejects blank name', function () {
    $svc = new DigitalOceanService('tok');
    $this->expectException(\InvalidArgumentException::class);
    $svc->snapshotDroplet(99, '   ');
});
test('wait for droplet action polls until completed', function () {
    Http::fakeSequence('api.digitalocean.com/v2/droplets/77/actions/5000')
        ->push(['action' => ['id' => 5000, 'status' => 'in-progress']], 200)
        ->push(['action' => ['id' => 5000, 'status' => 'in-progress']], 200)
        ->push(['action' => ['id' => 5000, 'status' => 'completed']], 200);

    $svc = new DigitalOceanService('tok');
    $ticks = [];
    $action = $svc->waitForDropletAction(
        77,
        5000,
        timeoutSeconds: 60,
        pollSeconds: 2,
        onTick: function (array $a) use (&$ticks): void {
            $ticks[] = $a['status'] ?? null;
        },
    );

    expect($action['status'])->toBe('completed');
    expect($ticks)->toBe(['in-progress', 'in-progress', 'completed']);
});
test('wait for droplet action throws on errored status', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/77/actions/5001' => Http::response(
            ['action' => ['id' => 5001, 'status' => 'errored']],
            200
        ),
    ]);

    $svc = new DigitalOceanService('tok');
    $this->expectException(\RuntimeException::class);
    $svc->waitForDropletAction(77, 5001, timeoutSeconds: 60, pollSeconds: 2);
});
test('get snapshots filters by resource type', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/snapshots*' => Http::response([
            'snapshots' => [['id' => '1', 'name' => 'a'], ['id' => '2', 'name' => 'b']],
        ], 200),
    ]);

    $svc = new DigitalOceanService('tok');
    $snapshots = $svc->getSnapshots('droplet');

    expect($snapshots)->toHaveCount(2);
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'resource_type=droplet');
    });
});
test('delete snapshot calls api', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/snapshots/abc' => Http::response('', 204),
    ]);

    $svc = new DigitalOceanService('tok');
    $svc->deleteSnapshot('abc');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/snapshots/abc');
    });
});
test('delete ssh key url encodes fingerprint', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account/keys/*' => Http::response('', 204),
    ]);

    $svc = new DigitalOceanService('tok');
    $svc->deleteSshKey('aa:bb:cc:dd');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/account/keys/aa%3Abb%3Acc%3Add');
    });
});
