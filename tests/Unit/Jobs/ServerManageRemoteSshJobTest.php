<?php

declare(strict_types=1);

use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Services\Servers\ServerManageSshExecutor;

test('manage ssh jobs type-hint the services ServerManageSshExecutor class', function (): void {
    foreach ([ServerManageRemoteSshJob::class, SyncServerSystemdServicesJob::class] as $jobClass) {
        $method = new ReflectionMethod($jobClass, 'handle');
        $parameter = $method->getParameters()[0];
        $type = $parameter->getType();

        expect($type)->not->toBeNull()
            ->and($type->getName())->toBe(ServerManageSshExecutor::class);
    }
});

test('container resolves ServerManageSshExecutor for manage remote ssh job handle', function (): void {
    $executor = app(ServerManageSshExecutor::class);

    expect($executor)->toBeInstanceOf(ServerManageSshExecutor::class);
});
