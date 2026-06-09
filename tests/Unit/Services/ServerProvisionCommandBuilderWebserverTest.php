<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerProvisionCommandBuilderWebserverTest;

use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;

test('build application stack supports apache openlitespeed and traefik', function () {
    $builder = app(ServerProvisionCommandBuilder::class);

    $apache = new Server([
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'apache',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);
    $openlitespeed = new Server([
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'openlitespeed',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);
    $traefik = new Server([
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'traefik',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    $apacheCommands = implode("\n", $builder->build($apache));
    $olsCommands = implode("\n", $builder->build($openlitespeed));
    $traefikCommands = implode("\n", $builder->build($traefik));

    $this->assertStringContainsString('apache2', $apacheCommands);
    $this->assertStringContainsString('apachectl configtest', $apacheCommands);

    $this->assertStringContainsString('openlitespeed', $olsCommands);
    $this->assertStringContainsString('lswsctrl', $olsCommands);

    $this->assertStringContainsString('traefik', $traefikCommands);
    $this->assertStringContainsString('caddy', $traefikCommands);
});
