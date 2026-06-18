<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold\ScaffoldPrerequisitesTest;

use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\Scaffold\Services\PrerequisiteResult;
use App\Modules\Scaffold\Services\ScaffoldPrerequisites;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function server(): Server
{
    $user = User::factory()->create();

    return Server::factory()->ready()->create(['user_id' => $user->id]);
}
test('wp cli already present is a no op', function () {
    $server = server();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    // The presence check returns 0 (test -x); install must NOT run.
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
        ->andReturn(new ProcessOutput('', 0, false));

    $result = (new ScaffoldPrerequisites($executor))->ensureWpCli($server);

    expect($result->state)->toBe(PrerequisiteResult::STATE_PRESENT);
    expect($result->ok())->toBeTrue();
});
test('wp cli missing triggers install', function () {
    $server = server();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
        ->andReturn(new ProcessOutput('', 1, false));
    // not present
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, string $name, string $bash): bool {
            expect($name)->toBe('scaffold-prerequisites:install-wp-cli');
            $this->assertStringContainsString('wp-cli.phar', $bash);

            return true;
        })
        ->andReturn(new ProcessOutput('wp-cli 2.x', 0, false));

    $result = (new ScaffoldPrerequisites($executor))->ensureWpCli($server);

    expect($result->state)->toBe(PrerequisiteResult::STATE_INSTALLED);
    expect($result->ok())->toBeTrue();
});
test('wp cli install failure is propagated', function () {
    $server = server();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
        ->andReturn(new ProcessOutput('', 1, false));
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:install-wp-cli')
        ->andReturn(new ProcessOutput('curl: (6) Could not resolve host', 6, false));

    $result = (new ScaffoldPrerequisites($executor))->ensureWpCli($server);

    expect($result->state)->toBe(PrerequisiteResult::STATE_FAILED);
    expect($result->ok())->toBeFalse();
    $this->assertStringContainsString('Could not resolve host', $result->error);
});
test('composer already present is a no op', function () {
    $server = server();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
        ->andReturn(new ProcessOutput('', 0, false));

    $result = (new ScaffoldPrerequisites($executor))->ensureComposer($server);

    expect($result->state)->toBe(PrerequisiteResult::STATE_PRESENT);
});
test('missing for returns binary name when absent', function () {
    $server = server();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->andReturn(new ProcessOutput('', 1, false));

    // not present
    $svc = new ScaffoldPrerequisites($executor);

    expect($svc->missingFor($server, 'wordpress'))->toBe('wp-cli');
    expect($svc->missingFor($server, 'laravel'))->toBe('composer');
    expect($svc->missingFor($server, 'unknown-framework'))->toBeNull();
});
test('missing for returns null when present', function () {
    $server = server();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->andReturn(new ProcessOutput('', 0, false));

    $svc = new ScaffoldPrerequisites($executor);

    expect($svc->missingFor($server, 'wordpress'))->toBeNull();
    expect($svc->missingFor($server, 'laravel'))->toBeNull();
});
