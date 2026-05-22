<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessTargetCapabilityResolverTest;
use App\Models\Server;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
test('digitalocean functions advertises all four openwhisk runtimes', function () {
    $server = (new Server)->forceFill([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $capabilities = (new ServerlessTargetCapabilityResolver)->forServer($server);

    // DO Functions = managed Apache OpenWhisk: Node, Python, PHP, Go.
    expect($capabilities['supports_php_runtime'])->toBeTrue();
    expect($capabilities['supports_node_runtime'])->toBeTrue();
    expect($capabilities['supports_python_runtime'])->toBeTrue();
    expect($capabilities['supports_go_runtime'])->toBeTrue();
});
test('aws lambda advertises all four runtimes', function () {
    $server = (new Server)->forceFill([
        'meta' => ['host_kind' => Server::HOST_KIND_AWS_LAMBDA],
    ]);

    $capabilities = (new ServerlessTargetCapabilityResolver)->forServer($server);

    expect($capabilities['supports_php_runtime'])->toBeTrue();
    expect($capabilities['supports_node_runtime'])->toBeTrue();
    expect($capabilities['supports_python_runtime'])->toBeTrue();
    expect($capabilities['supports_go_runtime'])->toBeTrue();
});
test('unknown target advertises no runtimes', function () {
    $capabilities = (new ServerlessTargetCapabilityResolver)->forServer(null);

    expect($capabilities['target'])->toBe('unknown');
    expect($capabilities['supports_php_runtime'])->toBeFalse();
    expect($capabilities['supports_node_runtime'])->toBeFalse();
    expect($capabilities['supports_python_runtime'])->toBeFalse();
    expect($capabilities['supports_go_runtime'])->toBeFalse();
});
