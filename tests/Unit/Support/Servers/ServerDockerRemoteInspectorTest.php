<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\ServerDockerRemoteInspectorTest;

use App\Models\Server;
use App\Support\Servers\ServerDockerRemoteInspector;

test('parse container lines includes ports column', function (): void {
    $inspector = app(ServerDockerRemoteInspector::class);
    $method = new \ReflectionMethod($inspector, 'parseContainerLines');
    $method->setAccessible(true);

    $output = "abc123\tweb\tnginx:alpine\tUp 2 hours\trunning\t0.0.0.0:80->80/tcp\n";
    $rows = $method->invoke($inspector, $output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('web');
    expect($rows[0]['ports'])->toBe('0.0.0.0:80->80/tcp');
});

test('isValidImageRef accepts repository tags and ids', function (): void {
    $inspector = app(ServerDockerRemoteInspector::class);

    expect($inspector->isValidImageRef('nginx:alpine'))->toBeTrue();
    expect($inspector->isValidImageRef('sha256:abcd1234'))->toBeTrue();
    expect($inspector->isValidImageRef('bad ref!'))->toBeFalse();
});

test('dockerCliPresent reads manage_docker meta', function (): void {
    $server = Server::factory()->make([
        'meta' => ['manage_docker' => ['present' => true]],
    ]);

    expect(app(ServerDockerRemoteInspector::class)->dockerCliPresent($server))->toBeTrue();
});
