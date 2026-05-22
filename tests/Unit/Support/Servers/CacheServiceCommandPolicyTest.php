<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\CacheServiceCommandPolicyTest;
use App\Support\Servers\CacheServiceCommandPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
/**
 * @return iterable<string, array{string}>
 */
dataset('readOnlyCommands', function () {
    yield 'INFO' => ['INFO'];
    yield 'INFO server with arg' => ['INFO server'];
    yield 'PING' => ['PING'];
    yield 'lowercase ping' => ['ping'];
    yield 'mixed case Get' => ['Get foo'];
    yield 'KEYS pattern' => ['KEYS *'];
    yield 'GET' => ['GET foo'];
    yield 'MEMORY USAGE' => ['MEMORY USAGE foo'];
    yield 'memory stats lowercase' => ['memory stats'];
    yield 'CONFIG GET' => ['CONFIG GET maxmemory'];
    yield 'CLIENT LIST' => ['CLIENT LIST'];
    yield 'SLOWLOG GET' => ['SLOWLOG GET 50'];
    yield 'leading and trailing whitespace' => ['   GET   foo   '];
    yield 'collapsed multiple spaces' => ['GET    foo'];
});
/**
 * @return iterable<string, array{string}>
 */
dataset('mutatingCommands', function () {
    yield 'SET' => ['SET foo bar'];
    yield 'DEL' => ['DEL foo'];
    yield 'FLUSHALL' => ['FLUSHALL'];
    yield 'FLUSHDB' => ['FLUSHDB'];
    yield 'CONFIG SET' => ['CONFIG SET maxmemory 1gb'];
    yield 'CONFIG REWRITE' => ['CONFIG REWRITE'];
    yield 'EXPIRE' => ['EXPIRE foo 60'];
    yield 'HSET' => ['HSET h f v'];
    yield 'bare MEMORY (single token)' => ['MEMORY'];
    yield 'bare CONFIG (single token)' => ['CONFIG'];
    yield 'bare CLIENT (single token)' => ['CLIENT'];
});
/**
 * @return iterable<string, array{string}>
 */
dataset('blockedCommands', function () {
    yield 'SHUTDOWN' => ['SHUTDOWN'];
    yield 'SHUTDOWN NOSAVE' => ['SHUTDOWN NOSAVE'];
    yield 'DEBUG SLEEP' => ['DEBUG SLEEP 5'];
    yield 'DEBUG RESTART' => ['DEBUG RESTART'];
    yield 'MIGRATE' => ['MIGRATE 1.2.3.4 6379 foo 0 5000'];
    yield 'REPLICAOF' => ['REPLICAOF host 6379'];
    yield 'SLAVEOF' => ['SLAVEOF host 6379'];
    yield 'CLUSTER RESET' => ['CLUSTER RESET HARD'];
    yield 'BGREWRITEAOF' => ['BGREWRITEAOF'];
    yield 'lowercase shutdown' => ['shutdown'];
});
test('read only commands pass the read only check', function (string $command) {
    $policy = new CacheServiceCommandPolicy;
    expect($policy->isReadOnly($command))->toBeTrue("Expected '{$command}' to be read-only");
    expect($policy->isBlocked($command))->toBeFalse("Expected '{$command}' to NOT be blocked");
})->with('readOnlyCommands');
test('mutating commands fail the read only check', function (string $command) {
    $policy = new CacheServiceCommandPolicy;
    expect($policy->isReadOnly($command))->toBeFalse("Expected '{$command}' to NOT be read-only");
    expect($policy->isBlocked($command))->toBeFalse("Expected '{$command}' to NOT be blocked (mutating but not blocked)");
})->with('mutatingCommands');
test('blocked commands are blocked', function (string $command) {
    $policy = new CacheServiceCommandPolicy;
    expect($policy->isBlocked($command))->toBeTrue("Expected '{$command}' to be blocked");

    // Blocked commands are also not read-only — they should fail unlock + read-only checks.
    expect($policy->isReadOnly($command))->toBeFalse("Expected '{$command}' to NOT be read-only");
})->with('blockedCommands');
test('empty command is neither read only nor blocked', function () {
    $policy = new CacheServiceCommandPolicy;
    expect($policy->isReadOnly(''))->toBeFalse();
    expect($policy->isReadOnly('   '))->toBeFalse();
    expect($policy->isBlocked(''))->toBeFalse();
    expect($policy->isBlocked('   '))->toBeFalse();
});
