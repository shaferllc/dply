<?php

declare(strict_types=1);

namespace Tests\Unit\Queue\DockerWorkerRegistryTest;

use App\Modules\Queue\Services\Runtimes\DockerWorkerRuntime;

/**
 * Docker's own rule, which is easy to get wrong: the first path segment is a
 * registry host only when it looks like one. `acme/app` is Docker Hub with an
 * org called acme — logging in to a host named "acme" would simply fail.
 */
test('a registry host is only recognised when it looks like one', function (string $image, string $expected) {
    expect(DockerWorkerRuntime::registryHostFor($image))->toBe($expected);
})->with([
    ['ghcr.io/acme/app:latest', 'ghcr.io'],
    ['registry.gitlab.com/acme/app', 'registry.gitlab.com'],
    ['localhost:5000/app:1', 'localhost:5000'],
    ['localhost/app', 'localhost'],
    // Docker Hub, in all its shapes.
    ['acme/app:latest', ''],
    ['postgres:16', ''],
    ['postgres', ''],
]);
