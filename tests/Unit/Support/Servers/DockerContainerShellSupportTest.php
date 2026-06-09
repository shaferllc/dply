<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Support\Servers\DockerContainerShellSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('remote exec command wraps docker exec safely', function (): void {
    $command = DockerContainerShellSupport::remoteExecCommand('abc123', 'php artisan migrate --force');

    expect($command)->toContain('docker exec')
        ->and($command)->toContain("'abc123'")
        ->and($command)->toContain("'php artisan migrate --force'");
});

test('local interactive ssh one liner uses server ssh user and ip', function (): void {
    $server = Server::factory()->ready()->make([
        'ssh_user' => 'dply',
        'ip_address' => '203.0.113.10',
    ]);

    $line = DockerContainerShellSupport::localInteractiveSshOneLiner($server, 'my-container');

    expect($line)->toBe('ssh -t dply@203.0.113.10 "sudo docker exec -it \'my-container\' sh"');
});
