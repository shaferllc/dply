<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessLaravelHandlerCommandTest;
use RuntimeException;

beforeEach(function () {
    // The handler file only declares functions (function_exists-guarded);
    // requiring it has no side effects.
    require_once resource_path('serverless/digitalocean-functions-laravel-handler.php');
});
test('a tick authorises when the secret is in the bundled env', function () {
    // The exact production scenario: the secret lives only in .env, never
    // as a real environment variable.
    $task = dply_do_functions_command(
        ['__ow_headers' => ['x-dply-run' => 'schedule', 'x-dply-secret' => 's3cret']],
        ['DPLY_COMMAND_SECRET' => 's3cret'],
    );

    expect($task)->toBe(['schedule:run', []]);
});
test('a queue tick returns the queue worker command', function () {
    $task = dply_do_functions_command(
        ['__ow_headers' => ['x-dply-run' => 'queue', 'x-dply-secret' => 's3cret']],
        ['DPLY_COMMAND_SECRET' => 's3cret'],
    );

    expect($task[0])->toBe('queue:work');
});
test('a mismatched secret is rejected', function () {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('invalid command secret');

    dply_do_functions_command(
        ['__ow_headers' => ['x-dply-run' => 'schedule', 'x-dply-secret' => 'wrong']],
        ['DPLY_COMMAND_SECRET' => 's3cret'],
    );
});
test('an absent secret is rejected', function () {
    $this->expectException(RuntimeException::class);

    dply_do_functions_command(
        ['__ow_headers' => ['x-dply-run' => 'schedule', 'x-dply-secret' => 'anything']],
        [],
    );
});
test('a normal request is not a command', function () {
    expect(dply_do_functions_command(['__ow_headers' => ['x-dply-path' => '/']], []))->toBeNull();
    expect(dply_do_functions_command([], []))->toBeNull();
});
