<?php

namespace Tests\Unit\HostCapabilitiesTest;

use App\Models\Server;

test('vm hosts keep ssh capabilities by default', function () {
    $server = new Server([
        'meta' => [],
    ]);

    $capabilities = $server->hostCapabilities();

    expect($server->isVmHost())->toBeTrue();
    expect($capabilities->supportsSsh())->toBeTrue();
    expect($capabilities->supportsWebserverProvisioning())->toBeTrue();
    expect($capabilities->supportsFunctionDeploy())->toBeFalse();
});

test('digitalocean functions hosts disable machine features', function () {
    $server = new Server([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
        ],
    ]);

    $capabilities = $server->hostCapabilities();

    expect($server->isDigitalOceanFunctionsHost())->toBeTrue();
    expect($capabilities->supportsSsh())->toBeFalse();
    expect($capabilities->supportsWebserverProvisioning())->toBeFalse();
    expect($capabilities->supportsEnvPushToHost())->toBeFalse();
    expect($capabilities->supportsFunctionDeploy())->toBeTrue();
    expect($server->providerDisplayLabel())->toBe('DigitalOcean Functions');
});

test('docker hosts expose container capabilities without vm features', function () {
    $server = new Server([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DOCKER,
        ],
    ]);

    $capabilities = $server->hostCapabilities();

    expect($server->isDockerHost())->toBeTrue();
    expect($capabilities->supportsSsh())->toBeFalse();
    expect($capabilities->supportsContainerDeploy())->toBeTrue();
    expect($capabilities->supportsClusterDeploy())->toBeFalse();
    expect($capabilities->supportsWebserverProvisioning())->toBeFalse();
    expect($server->providerDisplayLabel())->toBe('Docker');
});

test('kubernetes clusters expose cluster capabilities without vm features', function () {
    $server = new Server([
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
        ],
    ]);

    $capabilities = $server->hostCapabilities();

    expect($server->isKubernetesCluster())->toBeTrue();
    expect($capabilities->supportsSsh())->toBeFalse();
    expect($capabilities->supportsContainerDeploy())->toBeFalse();
    expect($capabilities->supportsClusterDeploy())->toBeTrue();
    expect($capabilities->supportsIngressManagement())->toBeTrue();
    expect($server->providerDisplayLabel())->toBe('Kubernetes');
});
