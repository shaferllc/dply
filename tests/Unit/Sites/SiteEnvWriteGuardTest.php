<?php

declare(strict_types=1);

namespace Tests\Unit\Sites\SiteEnvWriteGuardTest;

use App\Services\Sites\SiteEnvValidator;
use App\Services\Sites\SiteEnvWriteGuard;
use App\Services\SshConnection;

function guard(): SiteEnvWriteGuard
{
    return new SiteEnvWriteGuard(new SiteEnvValidator);
}

/** A valid base64 32-byte APP_KEY so the danger gate doesn't trip on it. */
function appKey(): string
{
    return 'base64:'.base64_encode(str_repeat('a', 32));
}

test('a healthy env has no danger findings and passes the static gate', function () {
    $vars = [
        'APP_KEY' => appKey(),
        'APP_ENV' => 'production',
        'APP_URL' => 'https://example.com',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_DATABASE' => 'app',
        'DB_USERNAME' => 'app',
        'DB_PASSWORD' => 'secret-value',
    ];

    expect(guard()->dangers($vars))->toBe([]);
    guard()->assertSafeToWrite($vars); // does not throw
});

test('an empty APP_KEY is a danger and blocks the write', function () {
    $vars = ['APP_KEY' => '', 'APP_ENV' => 'production'];

    $dangers = guard()->dangers($vars);
    expect($dangers)->not->toBe([]);
    expect(collect($dangers)->pluck('key'))->toContain('APP_KEY');

    expect(fn () => guard()->assertSafeToWrite($vars))
        ->toThrow(\RuntimeException::class, 'refusing to write');
});

test('a broadcaster with no credentials blocks the write', function () {
    $vars = [
        'APP_KEY' => appKey(),
        'BROADCAST_CONNECTION' => 'reverb',
        // REVERB_APP_KEY / _ID / _SECRET deliberately absent
    ];

    expect(fn () => guard()->assertSafeToWrite($vars))
        ->toThrow(\RuntimeException::class);

    expect(collect(guard()->dangers($vars))->pluck('key'))
        ->toContain('REVERB_APP_KEY');
});

test('warnings alone do not block the write', function () {
    // APP_DEBUG=true outside production is a warn, not a danger.
    $vars = ['APP_KEY' => appKey(), 'APP_ENV' => 'local', 'APP_DEBUG' => 'true'];

    expect(guard()->dangers($vars))->toBe([]);
    guard()->assertSafeToWrite($vars); // does not throw
});

test('the live boot test throws when the app fails to boot', function () {
    $ssh = \Mockery::mock(SshConnection::class);
    $ssh->shouldReceive('exec')->once()->andReturn(
        "DPLY_ENVTEST_FAIL\nPHP Fatal error: could not load configuration"
    );

    expect(fn () => guard()->assertBootsOnServer($ssh, '/home/dply/example.com/current', '/tmp/dply-env-x'))
        ->toThrow(\RuntimeException::class, 'live boot test');
});

test('the live boot test passes on OK and on any skip marker', function () {
    foreach (['DPLY_ENVTEST_OK', 'DPLY_ENVTEST_SKIP_NOAPP', 'DPLY_ENVTEST_SKIP_STAGE'] as $marker) {
        $ssh = \Mockery::mock(SshConnection::class);
        $ssh->shouldReceive('exec')->once()->andReturn($marker);

        guard()->assertBootsOnServer($ssh, '/home/dply/example.com/current', '/tmp/dply-env-x');
    }

    expect(true)->toBeTrue(); // reached here = no throw
});
