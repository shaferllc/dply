<?php

declare(strict_types=1);

namespace Tests\Feature\DplyRuntimeCheckCommandTest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('runtime check passes in default all mode without horizon', function () {
    config([
        'dply_runtime.mode' => 'all',
    ]);

    expect(Artisan::call('dply:runtime:check', ['--skip-horizon' => true]))->toBe(0);
});

test('runtime check fails when split worker has non-redis queue', function () {
    config([
        'dply_runtime.mode' => 'worker',
        'dply_runtime.worker_role' => 'replica',
        'queue.default' => 'database',
    ]);

    expect(Artisan::call('dply:runtime:check', ['--skip-horizon' => true]))->toBe(1);
});

test('runtime check fails when horizon inactive on worker split', function () {
    config([
        'dply_runtime.mode' => 'worker',
        'dply_runtime.worker_role' => 'replica',
        'queue.default' => 'redis',
    ]);

    expect(Artisan::call('dply:runtime:check'))->toBe(1);
});

test('dply about json includes runtime payload', function () {
    config([
        'dply_runtime.mode' => 'web',
        'queue.default' => 'redis',
    ]);

    Artisan::call('dply:about', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveKey('runtime')
        ->and($decoded['runtime']['mode'])->toBe('web')
        ->and($decoded['runtime']['runs_scheduler'])->toBeFalse()
        ->and($decoded['runtime']['expects_horizon'])->toBeFalse();
});

test('web runtime registers no scheduled tasks', function () {
    putenv('DPLY_RUNTIME=web');
    $this->refreshApplication();

    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('No scheduled tasks');

    putenv('DPLY_RUNTIME');
});

test('primary worker runtime registers scheduled tasks', function () {
    putenv('DPLY_RUNTIME=worker');
    putenv('DPLY_WORKER_ROLE=primary');
    $this->refreshApplication();

    config([
        'queue.default' => 'redis',
        'cache.default' => 'redis',
    ]);

    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('dispatch-server-health-checks');

    putenv('DPLY_RUNTIME');
    putenv('DPLY_WORKER_ROLE');
});
