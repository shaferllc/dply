<?php

namespace Tests\Unit\SiteRuntimeProfileTest;

use App\Models\Server;
use App\Models\Site;

test('sites on docker hosts default to docker runtime profile', function () {
    $server = new Server([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DOCKER,
        ],
    ]);

    $site = new Site;
    $site->setRelation('server', $server);

    expect($site->runtimeProfile())->toBe('docker_web');
    expect($site->usesDockerRuntime())->toBeTrue();
    expect($site->usesKubernetesRuntime())->toBeFalse();
});

test('sites on kubernetes hosts default to kubernetes runtime profile', function () {
    $server = new Server([
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
        ],
    ]);

    $site = new Site;
    $site->setRelation('server', $server);

    expect($site->runtimeProfile())->toBe('kubernetes_web');
    expect($site->usesKubernetesRuntime())->toBeTrue();
    expect($site->usesDockerRuntime())->toBeFalse();
});
