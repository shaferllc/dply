<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Redis\RedisConnectionTlsTest;

use App\Support\Redis\RedisConnectionTls;

test('digitalocean managed redis on 25061 resolves to tls', function () {
    $host = 'dply-dply-io-redis-zrjg7a.k.db.ondigitalocean.com';

    expect(RedisConnectionTls::requiresTls($host, 25061))->toBeTrue()
        ->and(RedisConnectionTls::scheme(null, $host, 25061))->toBe('tls')
        ->and(RedisConnectionTls::scheme('tcp', $host, '25061'))->toBe('tls');
});

test('ondigitalocean hostname without an explicit port is still tls', function () {
    expect(RedisConnectionTls::scheme(null, 'example.db.ondigitalocean.com', 6379))->toBe('tls');
});

test('plaintext redis url to a managed host is rewritten to rediss', function () {
    $url = 'redis://default:secret@example.db.ondigitalocean.com:25061';

    expect(RedisConnectionTls::url($url, null, null))->toBe('rediss://default:secret@example.db.ondigitalocean.com:25061');
});

test('local loopback stays tcp even on 25061', function () {
    expect(RedisConnectionTls::scheme(null, '127.0.0.1', 25061))->toBe('tcp')
        ->and(RedisConnectionTls::scheme(null, '127.0.0.1', 6379))->toBe('tcp')
        ->and(RedisConnectionTls::url('redis://127.0.0.1:6379', '127.0.0.1', 6379))->toBe('redis://127.0.0.1:6379');
});

test('ensureEnv adds scheme for a managed host without inventing a url', function () {
    $vars = RedisConnectionTls::ensureEnv([
        'REDIS_HOST' => 'cache.db.ondigitalocean.com',
        'REDIS_PORT' => '25061',
        'REDIS_PASSWORD' => 'secret',
    ]);

    expect($vars['REDIS_SCHEME'])->toBe('tls')
        ->and($vars)->not->toHaveKey('REDIS_URL');
});
