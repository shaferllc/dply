<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessLaravelHandlerCommandTest;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

test('image responses are base64-encoded so OpenWhisk can decode them', function () {
    $png = hex2bin('89504e470d0a1a0a');

    $response = dply_do_functions_web_response(200, [
        'Content-Type' => 'image/png',
    ], $png);

    expect($response['statusCode'])->toBe(200);
    expect($response['headers']['Content-Type'])->toBe('image/png');
    expect($response['body'])->toBe(base64_encode($png));
    expect(json_encode($response))->not->toBeFalse();
});

test('html css js and json responses stay as plain text', function () {
    $html = dply_do_functions_web_response(200, ['content-type' => 'text/html; charset=utf-8'], '<p>ok</p>');
    $css = dply_do_functions_web_response(200, ['content-type' => 'text/css'], 'body{color:red}');
    $js = dply_do_functions_web_response(200, ['Content-Type' => 'application/javascript'], 'console.log(1)');
    $json = dply_do_functions_web_response(200, ['Content-Type' => 'application/json'], '{"ok":true}');

    expect($html['body'])->toBe('<p>ok</p>');
    expect($css['body'])->toBe('body{color:red}');
    expect($js['body'])->toBe('console.log(1)');
    expect($json['body'])->toBe('{"ok":true}');
});

test('svg fonts and downloads are encoded because OpenWhisk treats them as binary', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
    $woff2 = random_bytes(32);
    $pdf = '%PDF-1.4 binary '.random_bytes(8);

    expect(dply_do_functions_web_response(200, ['content-type' => 'image/svg+xml'], $svg)['body'])
        ->toBe(base64_encode($svg));
    expect(dply_do_functions_web_response(200, ['content-type' => 'font/woff2'], $woff2)['body'])
        ->toBe(base64_encode($woff2));
    expect(dply_do_functions_web_response(200, ['Content-Type' => 'application/pdf'], $pdf)['body'])
        ->toBe(base64_encode($pdf));
});

test('public binary files are base64-encoded for the OpenWhisk web gateway', function () {
    $root = sys_get_temp_dir().'/dply-fn-public-bin-'.uniqid();
    mkdir($root.'/public', 0777, true);
    $png = hex2bin('89504e470d0a1a0a0000000d49484452');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    file_put_contents($root.'/public/photo.png', $png);
    file_put_contents($root.'/public/logo.svg', $svg);

    $pngResponse = dply_do_functions_public_response($root, '/photo.png');
    $svgResponse = dply_do_functions_public_response($root, '/logo.svg');

    expect($pngResponse['headers']['content-type'])->toBe('image/png');
    expect($pngResponse['body'])->toBe(base64_encode($png));
    expect($svgResponse['headers']['content-type'])->toBe('image/svg+xml');
    expect($svgResponse['body'])->toBe(base64_encode($svg));
});

test('file downloads read the on-disk bytes instead of an empty getContent', function () {
    $path = sys_get_temp_dir().'/dply-fn-download-'.uniqid().'.pdf';
    $bytes = '%PDF-1.4 '.random_bytes(16);
    file_put_contents($path, $bytes);

    $response = new BinaryFileResponse($path);
    $response->headers->set('Content-Type', 'application/pdf');

    expect(dply_do_functions_response_body($response))->toBe($bytes);

    @unlink($path);
});

test('packaged bootstrap cache is preferred over empty tmp redirects', function () {
    $root = sys_get_temp_dir().'/dply-fn-cache-'.uniqid();
    mkdir($root.'/bootstrap/cache', 0777, true);
    file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');
    $tmp = $root.'/tmp-bootstrap';

    $paths = dply_do_functions_bootstrap_cache_env($root, $tmp);

    expect($paths['APP_CONFIG_CACHE'])->toBe($root.'/bootstrap/cache/config.php');
    expect($paths['APP_ROUTES_CACHE'])->toBe($tmp.'/routes.php');
});

test('warm max requests defaults to 250 and is capped', function () {
    expect(dply_do_functions_warm_max_requests([]))->toBe(250);
    expect(dply_do_functions_warm_max_requests(['DPLY_WARM_MAX_REQUESTS' => '10']))->toBe(10);
    expect(dply_do_functions_warm_max_requests(['DPLY_WARM_MAX_REQUESTS' => '99999']))->toBe(10000);
});

test('durable maintenance is on for the bound parameter or env flag', function () {
    expect(dply_do_functions_maintenance_enabled(['__dply_maintenance' => true], []))->toBeTrue();
    expect(dply_do_functions_maintenance_enabled([], ['DPLY_MAINTENANCE' => '1']))->toBeTrue();
    expect(dply_do_functions_maintenance_enabled([], ['DPLY_MAINTENANCE' => '0']))->toBeFalse();
    expect(dply_do_functions_maintenance_enabled([], []))->toBeFalse();
});

test('build assets are skipped on the function when asset url is off-function', function () {
    $env = [
        'APP_URL' => 'https://app.example.test',
        'ASSET_URL' => 'https://dply.example/serverless-assets/1',
    ];

    expect(dply_do_functions_off_function_assets($env, '/build/assets/app.css'))->toBeTrue();
    expect(dply_do_functions_off_function_assets($env, '/favicon.ico'))->toBeFalse();
    expect(dply_do_functions_off_function_assets([
        'APP_URL' => 'https://app.example.test',
        'ASSET_URL' => 'https://app.example.test',
    ], '/build/assets/app.css'))->toBeFalse();
});

test('a signed allowlisted artisan command is accepted', function () {
    $command = 'migrate --force';
    $secret = 's3cret';
    $task = dply_do_functions_command(
        ['__ow_headers' => [
            'x-dply-run' => 'artisan',
            'x-dply-secret' => $secret,
            'x-dply-artisan' => $command,
            'x-dply-signature' => dply_do_functions_artisan_signature($secret, $command),
        ]],
        ['DPLY_COMMAND_SECRET' => $secret],
    );

    expect($task[0])->toBe('migrate');
    expect($task[1]['--force'])->toBeTrue();
});

test('artisan migrate implies force when omitted', function () {
    expect(dply_do_functions_parse_artisan('migrate'))->toBe(['migrate', ['--force' => true]]);
});

test('an unsigned artisan command is rejected', function () {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('invalid artisan signature');

    dply_do_functions_command(
        ['__ow_headers' => [
            'x-dply-run' => 'artisan',
            'x-dply-secret' => 's3cret',
            'x-dply-artisan' => 'migrate',
        ]],
        ['DPLY_COMMAND_SECRET' => 's3cret'],
    );
});

test('a non-allowlisted artisan command is rejected', function () {
    $secret = 's3cret';
    $command = 'tinker';

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('not allowlisted');

    dply_do_functions_command(
        ['__ow_headers' => [
            'x-dply-run' => 'artisan',
            'x-dply-secret' => $secret,
            'x-dply-artisan' => $command,
            'x-dply-signature' => dply_do_functions_artisan_signature($secret, $command),
        ]],
        ['DPLY_COMMAND_SECRET' => $secret],
    );
});

test('artisan rejects shell metacharacters', function () {
    $this->expectException(RuntimeException::class);

    dply_do_functions_parse_artisan('migrate; whoami');
});
