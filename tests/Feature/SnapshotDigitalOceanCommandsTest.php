<?php

declare(strict_types=1);

namespace Tests\Feature\SnapshotDigitalOceanCommandsTest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
test('list command renders snapshots as json', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/snapshots*' => Http::response([
            'snapshots' => [
                [
                    'id' => '111',
                    'name' => 'dply-base-20260504-aaaa',
                    'distribution' => 'Ubuntu',
                    'regions' => ['nyc1', 'nyc3'],
                    'size_gigabytes' => 2.1,
                    'created_at' => '2026-05-04T10:00:00Z',
                    'resource_id' => 9001,
                ],
                [
                    'id' => '222',
                    'name' => 'something-else',
                    'distribution' => 'Ubuntu',
                    'regions' => ['fra1'],
                    'size_gigabytes' => 5.0,
                    'created_at' => '2026-05-03T12:00:00Z',
                    'resource_id' => 9002,
                ],
            ],
        ], 200),
    ]);

    $exit = Artisan::call('dply:do:snapshot:list', [
        '--token' => 'do-token',
        '--prefix' => 'dply-base-',
        '--json' => true,
    ]);

    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveCount(1);
    expect($decoded[0]['id'])->toBe('111');
    expect($decoded[0]['name'])->toBe('dply-base-20260504-aaaa');
});
test('delete command calls api and succeeds', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/snapshots/abc123' => Http::response('', 204),
    ]);

    $exit = Artisan::call('dply:do:snapshot:delete', [
        'snapshot_id' => 'abc123',
        '--token' => 'do-token',
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Deleted snapshot abc123', Artisan::output());

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/snapshots/abc123');
    });
});
test('delete command surfaces api failure', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/snapshots/*' => Http::response(
            ['message' => 'snapshot not found'],
            404
        ),
    ]);

    $exit = Artisan::call('dply:do:snapshot:delete', [
        'snapshot_id' => 'missing',
        '--token' => 'do-token',
        '--force' => true,
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('snapshot not found', Artisan::output());
});
