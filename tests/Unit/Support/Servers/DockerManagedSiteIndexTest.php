<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Support\Servers\DockerManagedSiteIndex;
use App\Support\Servers\ServerDockerRemoteInspector;

test('isValidExecCommand rejects multiline commands', function (): void {
    $inspector = app(ServerDockerRemoteInspector::class);

    expect($inspector->isValidExecCommand('php artisan migrate'))->toBeTrue();
    expect($inspector->isValidExecCommand("echo one\necho two"))->toBeFalse();
});

test('primaryComposeConfigFile uses first path when comma separated', function (): void {
    $inspector = app(ServerDockerRemoteInspector::class);

    expect($inspector->primaryComposeConfigFile('/srv/a/docker-compose.yml, /srv/a/override.yml'))
        ->toBe('/srv/a/docker-compose.yml');
});

test('docker managed site index maps compose project and container names', function (): void {
    $server = Server::factory()->make(['id' => '01testserver000000000000000']);
    $site = Site::factory()->make([
        'id' => '01testsite00000000000000000',
        'server_id' => $server->id,
        'name' => 'My App',
        'slug' => 'my-app',
        'meta' => [
            'runtime_profile' => 'docker_web',
            'docker_runtime' => ['compose_yaml' => 'services: {}'],
        ],
    ]);
    $site->setRelation('server', $server);
    $server->setRelation('sites', collect([$site]));

    $index = DockerManagedSiteIndex::for($server);

    expect($index['sites'])->toHaveCount(1);
    expect($index['project_to_site']['my-app']['name'])->toBe('My App');

    $linked = DockerManagedSiteIndex::siteForContainer(['name' => 'my-app-web-1'], $index);
    expect($linked['slug'])->toBe('my-app');
});
