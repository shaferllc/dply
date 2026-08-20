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

test('the request host prefers the forwarded function hostname over the OpenWhisk controller', function () {
    $request = dply_do_functions_request([
        '__ow_method' => 'GET',
        '__ow_path' => '/',
        '__ow_headers' => [
            'host' => 'ccontroller',
            'x-forwarded-host' => 'placehold-47f60b69.dply-serverless.cloud',
            'x-forwarded-proto' => 'https',
        ],
    ], ['APP_URL' => 'https://placehold-47f60b69.dply-serverless.cloud']);

    expect($request->getHost())->toBe('placehold-47f60b69.dply-serverless.cloud');
    expect($request->getScheme())->toBe('https');
});

test('the request host falls back to APP_URL when OpenWhisk only sends its controller host', function () {
    $request = dply_do_functions_request([
        '__ow_method' => 'GET',
        '__ow_path' => '/',
        '__ow_headers' => ['host' => 'ccontroller'],
    ], ['APP_URL' => 'https://placehold-47f60b69.dply-serverless.cloud']);

    expect($request->getHost())->toBe('placehold-47f60b69.dply-serverless.cloud');
});

test('public files under public/ are served without hitting laravel routes', function () {
    $root = sys_get_temp_dir().'/dply-fn-public-'.uniqid();
    mkdir($root.'/public/build/assets', 0777, true);
    file_put_contents($root.'/public/build/assets/app.css', 'body{color:red}');

    $response = dply_do_functions_public_response($root, '/build/assets/app.css');

    expect($response['statusCode'])->toBe(200);
    expect($response['headers']['content-type'])->toBe('text/css');
    expect($response['body'])->toBe('body{color:red}');

    expect(dply_do_functions_public_response($root, '/missing.css'))->toBeNull();
    expect(dply_do_functions_public_response($root, '/../.env'))->toBeNull();
});
