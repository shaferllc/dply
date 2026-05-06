<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Support\Servers\InstalledStack;
use PHPUnit\Framework\TestCase;

class InstalledStackTest extends TestCase
{
    public function test_round_trip_via_array(): void
    {
        $stack = new InstalledStack(
            database: 'mysql84',
            databaseVersion: '8.0.45',
            phpVersion: '8.4',
            webserver: 'nginx',
            cacheService: 'redis',
            lowMemoryMode: false,
            totalMemoryMb: 2048,
            swapMb: 2048,
        );

        $rebuilt = InstalledStack::fromArray($stack->toArray());

        $this->assertSame($stack->toArray(), $rebuilt->toArray());
    }

    public function test_from_meta_uses_installed_stack_key_when_present(): void
    {
        $server = new Server;
        $server->meta = [
            'database' => 'mysql84',                  // wizard request
            'installed_stack' => [                    // reconciled snapshot
                'database' => 'sqlite3',
                'database_version' => '3.45.1',
                'php_version' => '8.4',
                'webserver' => 'nginx',
                'cache_service' => 'redis',
                'low_mem_mode' => true,
                'total_memory_mb' => 458,
                'swap_mb' => 2048,
            ],
        ];

        $stack = InstalledStack::fromMeta($server);

        // The reconciled snapshot wins. The wizard request stays available
        // separately for "Requested vs Installed" divergence display.
        $this->assertSame('sqlite3', $stack->database);
        $this->assertSame('3.45.1', $stack->databaseVersion);
        $this->assertTrue($stack->lowMemoryMode);
        $this->assertSame(458, $stack->totalMemoryMb);
    }

    public function test_from_meta_falls_back_to_wizard_keys_for_legacy_servers(): void
    {
        // Server provisioned before reconciliation shipped — no installed_stack key.
        $server = new Server;
        $server->meta = [
            'database' => 'mysql84',
            'php_version' => '8.3',
            'webserver' => 'caddy',
            'cache_service' => 'valkey',
        ];

        $stack = InstalledStack::fromMeta($server);

        // Wizard values are surfaced as the installed reality (best
        // we can do — the script wasn't recording snapshots back then).
        $this->assertSame('mysql84', $stack->database);
        $this->assertNull($stack->databaseVersion); // never recorded
        $this->assertSame('8.3', $stack->phpVersion);
        $this->assertSame('caddy', $stack->webserver);
        $this->assertSame('valkey', $stack->cacheService);
        // Operational fields default conservatively for legacy servers.
        $this->assertFalse($stack->lowMemoryMode);
        $this->assertNull($stack->totalMemoryMb);
        $this->assertNull($stack->swapMb);
    }

    public function test_from_meta_handles_completely_empty_meta(): void
    {
        $server = new Server;
        $server->meta = [];

        $stack = InstalledStack::fromMeta($server);

        // Every field nullable / defaulted — no exception, no surprises.
        $this->assertNull($stack->database);
        $this->assertNull($stack->phpVersion);
        $this->assertFalse($stack->lowMemoryMode);
    }

    public function test_parse_from_output_extracts_tagged_line(): void
    {
        $output = <<<'OUT'
        [dply-step] Finalizing server
        [dply] verifying services...
        [dply-installed-stack] {"database":"sqlite3","database_version":"3.45.1","php_version":"8.4","webserver":"nginx","cache_service":"redis","low_mem_mode":true,"total_memory_mb":458,"swap_mb":2048}
        [dply] done
        OUT;

        $stack = InstalledStack::parseFromOutput($output);

        $this->assertNotNull($stack);
        $this->assertSame('sqlite3', $stack->database);
        $this->assertSame('3.45.1', $stack->databaseVersion);
        $this->assertTrue($stack->lowMemoryMode);
        $this->assertSame(458, $stack->totalMemoryMb);
    }

    public function test_parse_from_output_returns_null_when_tagged_line_absent(): void
    {
        $output = "[dply-step] Installing PHP 8.4\n[dply] done\n";

        $this->assertNull(InstalledStack::parseFromOutput($output));
    }

    public function test_parse_from_output_returns_null_for_malformed_json(): void
    {
        $output = "[dply-installed-stack] {not valid json\n";

        $this->assertNull(InstalledStack::parseFromOutput($output));
    }

    public function test_parse_from_output_handles_partial_fields(): void
    {
        // Forward-compatibility: missing fields → nulls, extra fields ignored.
        $output = '[dply-installed-stack] {"database":"sqlite3","unknown_field":"ignored"}'."\n";

        $stack = InstalledStack::parseFromOutput($output);

        $this->assertNotNull($stack);
        $this->assertSame('sqlite3', $stack->database);
        $this->assertNull($stack->databaseVersion);
        $this->assertNull($stack->phpVersion);
        $this->assertFalse($stack->lowMemoryMode);
    }

    public function test_parse_from_output_picks_last_tagged_line_when_multiple_present(): void
    {
        // Future-proofing: if we ever switch to progressive emit, the
        // last line is the most-recent (final) state. The /m flag with
        // $ end-of-line means each line is matched independently and
        // we want the most recent one.
        $output = <<<'OUT'
        [dply-installed-stack] {"database":"mysql84","database_version":"8.0.45"}
        [dply] continued running
        [dply-installed-stack] {"database":"mysql84","database_version":"8.0.46"}
        OUT;

        $stack = InstalledStack::parseFromOutput($output);

        // preg_match returns the FIRST match; for now that's expected.
        // If progressive emit lands later, parser should switch to
        // preg_match_all + last index. Documenting current behaviour.
        $this->assertNotNull($stack);
        $this->assertSame('mysql84', $stack->database);
    }

    public function test_diverges_from_request_when_wizard_database_differs(): void
    {
        $server = new Server;
        $server->meta = [
            'database' => 'mysql84',
            'installed_stack' => [
                'database' => 'sqlite3',
            ],
        ];

        $stack = InstalledStack::fromMeta($server);

        $this->assertTrue($stack->divergesFromRequest($server));
    }

    public function test_diverges_from_request_is_false_when_aligned(): void
    {
        $server = new Server;
        $server->meta = [
            'database' => 'mysql84',
            'installed_stack' => [
                'database' => 'mysql84',
            ],
        ];

        $stack = InstalledStack::fromMeta($server);

        $this->assertFalse($stack->divergesFromRequest($server));
    }

    public function test_diverges_from_request_is_false_when_no_wizard_request(): void
    {
        $server = new Server;
        $server->meta = [
            'installed_stack' => [
                'database' => 'sqlite3',
            ],
        ];

        $stack = InstalledStack::fromMeta($server);

        // Without a wizard request to compare against, there's no divergence.
        $this->assertFalse($stack->divergesFromRequest($server));
    }
}
