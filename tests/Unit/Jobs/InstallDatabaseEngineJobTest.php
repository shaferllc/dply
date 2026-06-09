<?php

declare(strict_types=1);

use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\UninstallDatabaseEngineJob;
use App\Support\Servers\ServerDatabaseHostCapabilities;

test('database engine jobs type-hint the support ServerDatabaseHostCapabilities class', function (): void {
    foreach ([InstallDatabaseEngineJob::class, UninstallDatabaseEngineJob::class] as $jobClass) {
        $method = new ReflectionMethod($jobClass, 'handle');
        $parameter = collect($method->getParameters())
            ->first(fn (ReflectionParameter $p) => $p->getType()?->getName() === ServerDatabaseHostCapabilities::class
                || $p->getName() === 'capabilities');

        expect($parameter)->not->toBeNull();

        $type = $parameter->getType();

        expect($type)->not->toBeNull()
            ->and($type->getName())->toBe(ServerDatabaseHostCapabilities::class);
    }
});

test('container resolves ServerDatabaseHostCapabilities for database engine job handle', function (): void {
    $capabilities = app(ServerDatabaseHostCapabilities::class);

    expect($capabilities)->toBeInstanceOf(ServerDatabaseHostCapabilities::class);
});
