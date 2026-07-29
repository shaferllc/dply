<?php

declare(strict_types=1);

use App\Models\SiteProcess;
use App\Modules\Deploy\Services\Manifest\DplyManifestParser;

test('SiteProcess matchesRuntimeRole honors worker:primary', function () {
    $process = new SiteProcess([
        'meta' => ['roles' => ['worker:primary']],
    ]);

    expect($process->matchesRuntimeRole('worker', 'primary'))->toBeTrue()
        ->and($process->matchesRuntimeRole('worker', 'replica'))->toBeFalse()
        ->and($process->matchesRuntimeRole('web', 'primary'))->toBeFalse()
        ->and($process->matchesRuntimeRole('all', 'primary'))->toBeTrue();
});

test('empty roles match every host', function () {
    $process = new SiteProcess(['meta' => null]);

    expect($process->matchesRuntimeRole('worker', 'replica'))->toBeTrue();
});

test('manifest parser reads process role and oneshot fields', function () {
    $manifest = app(DplyManifestParser::class)->parseRaw(<<<'YAML'
runtime: php
processes:
  scheduler:
    command: php artisan schedule:work
    roles: [worker:primary]
    type: scheduler
    stopwaitsecs: 60
  warm:
    command: php artisan warm
    roles: [worker]
    oneshot: true
    loop_seconds: 300
YAML, 'dply.yaml');

    expect($manifest->processes['scheduler']->roles)->toBe(['worker:primary'])
        ->and($manifest->processes['scheduler']->type)->toBe('scheduler')
        ->and($manifest->processes['scheduler']->stopwaitsecs)->toBe(60)
        ->and($manifest->processes['warm']->oneshot)->toBeTrue()
        ->and($manifest->processes['warm']->loopSeconds)->toBe(300)
        ->and($manifest->processes['warm']->meta()['oneshot'])->toBeTrue();
});
