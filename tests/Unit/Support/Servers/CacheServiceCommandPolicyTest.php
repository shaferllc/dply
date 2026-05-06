<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Support\Servers\CacheServiceCommandPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CacheServiceCommandPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function readOnlyCommands(): iterable
    {
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
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mutatingCommands(): iterable
    {
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
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedCommands(): iterable
    {
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
    }

    #[DataProvider('readOnlyCommands')]
    public function test_read_only_commands_pass_the_read_only_check(string $command): void
    {
        $policy = new CacheServiceCommandPolicy;
        $this->assertTrue($policy->isReadOnly($command), "Expected '{$command}' to be read-only");
        $this->assertFalse($policy->isBlocked($command), "Expected '{$command}' to NOT be blocked");
    }

    #[DataProvider('mutatingCommands')]
    public function test_mutating_commands_fail_the_read_only_check(string $command): void
    {
        $policy = new CacheServiceCommandPolicy;
        $this->assertFalse($policy->isReadOnly($command), "Expected '{$command}' to NOT be read-only");
        $this->assertFalse($policy->isBlocked($command), "Expected '{$command}' to NOT be blocked (mutating but not blocked)");
    }

    #[DataProvider('blockedCommands')]
    public function test_blocked_commands_are_blocked(string $command): void
    {
        $policy = new CacheServiceCommandPolicy;
        $this->assertTrue($policy->isBlocked($command), "Expected '{$command}' to be blocked");
        // Blocked commands are also not read-only — they should fail unlock + read-only checks.
        $this->assertFalse($policy->isReadOnly($command), "Expected '{$command}' to NOT be read-only");
    }

    public function test_empty_command_is_neither_read_only_nor_blocked(): void
    {
        $policy = new CacheServiceCommandPolicy;
        $this->assertFalse($policy->isReadOnly(''));
        $this->assertFalse($policy->isReadOnly('   '));
        $this->assertFalse($policy->isBlocked(''));
        $this->assertFalse($policy->isBlocked('   '));
    }
}
