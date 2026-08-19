<?php

declare(strict_types=1);

use App\Models\CloudDatabase;
use App\Support\Servers\DatabaseConnectionTarget;

function pgTarget(array $overrides = []): DatabaseConnectionTarget
{
    return new DatabaseConnectionTarget(
        engine: $overrides['engine'] ?? 'postgres',
        host: $overrides['host'] ?? 'db.example.ondigitalocean.com',
        port: $overrides['port'] ?? 25060,
        database: $overrides['database'] ?? 'defaultdb',
        username: $overrides['username'] ?? 'doadmin',
        sslMode: $overrides['sslMode'] ?? 'require',
    );
}

test('a uri omits the credential section entirely when no password is supplied', function (): void {
    $uri = pgTarget()->uri();

    // Emitting a literal PASSWORD placeholder produced commands that looked
    // copy-pasteable and then failed to authenticate. The username-only form is
    // valid, and clients prompt for the rest.
    expect($uri)->toBe('postgresql://doadmin@db.example.ondigitalocean.com:25060/defaultdb?sslmode=require')
        ->and($uri)->not->toContain('PASSWORD')
        ->and($uri)->not->toContain('secret');
});

test('a supplied password is url-encoded into the uri', function (): void {
    // Managed providers hand out passwords containing @ and / often enough that
    // naive concatenation produces a URI pointing at the wrong host entirely.
    $uri = pgTarget()->uri('p@ss/word');

    expect($uri)->toContain('p%40ss%2Fword')
        ->and($uri)->toStartWith('postgresql://doadmin:');
});

test('the tunnel uri targets localhost and downgrades to sslmode=require', function (): void {
    // verify-full cannot succeed against 127.0.0.1 — the certificate hostname
    // will never match — and SSH has already authenticated the transport.
    $uri = pgTarget(['sslMode' => 'verify-full'])->uri(null, '127.0.0.1', 15432);

    expect($uri)->toContain('@127.0.0.1:15432/')
        ->and($uri)->toContain('sslmode=require')
        ->and($uri)->not->toContain('verify-full');
});

test('the direct uri keeps the strict ssl mode', function (): void {
    expect(pgTarget(['sslMode' => 'verify-full'])->uri())->toContain('sslmode=verify-full');
});

test('mysql and redis get their own schemes and no sslmode query', function (): void {
    $mysql = pgTarget(['engine' => 'mysql', 'port' => 3306]);
    $redis = pgTarget(['engine' => 'redis', 'port' => 6379]);

    expect($mysql->uri())->toStartWith('mysql://')
        ->and($mysql->uri())->not->toContain('sslmode')
        ->and($redis->uri())->toStartWith('rediss://')
        ->and($redis->uri())->not->toContain('sslmode');
});

test('client commands match the engine', function (): void {
    expect(pgTarget()->clientCommand('127.0.0.1', 15432))
        ->toContain('psql')
        ->toContain('port=15432')
        ->toContain('dbname=defaultdb');

    expect(pgTarget(['engine' => 'mariadb'])->clientCommand('127.0.0.1', 15442))
        ->toContain('mysql')
        ->toContain('-P 15442');
});

test('serverless vendors are publicly reachable and expose no allowlist', function (string $backend): void {
    expect(DatabaseConnectionTarget::backendIsPubliclyReachable($backend))->toBeTrue()
        ->and(DatabaseConnectionTarget::backendSupportsTrustedSourceWrites($backend))->toBeFalse();
})->with([
    CloudDatabase::BACKEND_NEON,
    CloudDatabase::BACKEND_PLANETSCALE,
    CloudDatabase::BACKEND_SUPABASE,
    CloudDatabase::BACKEND_UPSTASH,
]);

test('only the iaas managed backends allow trusted-source writes', function (): void {
    expect(DatabaseConnectionTarget::backendSupportsTrustedSourceWrites(CloudDatabase::BACKEND_DIGITALOCEAN))->toBeTrue()
        ->and(DatabaseConnectionTarget::backendSupportsTrustedSourceWrites(CloudDatabase::BACKEND_VULTR))->toBeTrue()
        // dply did not provision an external database and must not firewall it.
        ->and(DatabaseConnectionTarget::backendSupportsTrustedSourceWrites(CloudDatabase::BACKEND_EXTERNAL))->toBeFalse();
});
