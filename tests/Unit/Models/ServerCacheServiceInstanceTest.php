<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ServerCacheService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit-level checks for the multi-instance helpers added when servers became
 * able to run multiple instances of the same engine on different ports. These
 * helpers gate the install scripts' choice between legacy (single-instance)
 * paths and templated (multi-instance) paths, so getting them wrong silently
 * is bad — be explicit in tests.
 */
class ServerCacheServiceInstanceTest extends TestCase
{
    public function test_default_instance_is_recognized(): void
    {
        $row = new ServerCacheService;
        $row->name = ServerCacheService::DEFAULT_INSTANCE_NAME;

        $this->assertTrue($row->isDefaultInstance());
    }

    public function test_named_instance_is_not_default(): void
    {
        $row = new ServerCacheService;
        $row->name = 'sessions';

        $this->assertFalse($row->isDefaultInstance());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'default reserved name' => ['default'];
        yield 'lowercase alpha' => ['primary'];
        yield 'with hyphen' => ['app-cache'];
        yield 'digits at end' => ['sessions01'];
        yield 'starting with digit then alpha' => ['1of2'];
        yield 'single character' => ['x'];
        yield 'thirty-two characters' => [str_repeat('a', 32)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace' => [' '];
        yield 'uppercase letter' => ['Primary'];
        yield 'underscore' => ['app_cache'];
        yield 'dot' => ['app.cache'];
        yield 'systemd metacharacter @' => ['app@1'];
        yield 'leading hyphen' => ['-cache'];
        yield 'thirty-three characters' => [str_repeat('a', 33)];
        yield 'colon' => ['app:cache'];
        yield 'slash' => ['app/cache'];
    }

    #[DataProvider('validNames')]
    public function test_valid_instance_names_pass(string $name): void
    {
        $this->assertTrue(
            ServerCacheService::isValidInstanceName($name),
            "Expected '{$name}' to be a valid instance name",
        );
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_instance_names_fail(string $name): void
    {
        $this->assertFalse(
            ServerCacheService::isValidInstanceName($name),
            "Expected '{$name}' to be rejected as an instance name",
        );
    }
}
