<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Scaffold;

use App\Support\Scaffold\DatabaseConnectionEnv;
use PHPUnit\Framework\TestCase;

class DatabaseConnectionEnvTest extends TestCase
{
    public function test_sqlite_emits_minimal_block_with_default_path(): void
    {
        $env = DatabaseConnectionEnv::forEngine('sqlite3', []);

        $this->assertSame("DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n", $env);
    }

    public function test_sqlite_uses_provided_absolute_path(): void
    {
        $env = DatabaseConnectionEnv::forEngine('sqlite3', [
            'sqlite_path' => '/home/dply/sites/example/database/database.sqlite',
        ]);

        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('DB_DATABASE=/home/dply/sites/example/database/database.sqlite', $env);
        // SQLite has no credentials concept — those keys MUST not appear.
        $this->assertStringNotContainsString('DB_HOST', $env);
        $this->assertStringNotContainsString('DB_USERNAME', $env);
        $this->assertStringNotContainsString('DB_PASSWORD', $env);
    }

    public function test_mysql_block_uses_default_host_and_port(): void
    {
        $env = DatabaseConnectionEnv::forEngine('mysql84', [
            'name' => 'myapp',
            'username' => 'dply_myapp',
            'password' => 'secret',
        ]);

        $this->assertStringContainsString('DB_CONNECTION=mysql', $env);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $env);
        $this->assertStringContainsString('DB_PORT=3306', $env);
        $this->assertStringContainsString('DB_DATABASE=myapp', $env);
        $this->assertStringContainsString('DB_USERNAME=dply_myapp', $env);
        $this->assertStringContainsString('DB_PASSWORD=secret', $env);
    }

    public function test_mysql_variants_all_route_to_mysql_block(): void
    {
        // Engine ID variants (mysql57 / mysql80 / mysql84 / plain mysql)
        // all use the mysql driver — the wizard's variant choice
        // matters at install time, not at .env time.
        foreach (['mysql', 'mysql57', 'mysql80', 'mysql84'] as $variant) {
            $env = DatabaseConnectionEnv::forEngine($variant, ['name' => 'a', 'username' => 'b', 'password' => 'c']);
            $this->assertStringContainsString('DB_CONNECTION=mysql', $env, "variant: {$variant}");
        }
    }

    public function test_mariadb_uses_dedicated_driver(): void
    {
        $env = DatabaseConnectionEnv::forEngine('mariadb114', [
            'name' => 'myapp', 'username' => 'u', 'password' => 'p',
        ]);

        $this->assertStringContainsString('DB_CONNECTION=mariadb', $env);
        $this->assertStringContainsString('DB_PORT=3306', $env);
    }

    public function test_postgres_uses_pgsql_driver_and_5432_port(): void
    {
        $env = DatabaseConnectionEnv::forEngine('postgres17', [
            'name' => 'myapp', 'username' => 'u', 'password' => 'p',
        ]);

        $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);
        $this->assertStringContainsString('DB_PORT=5432', $env);
    }

    public function test_unknown_engine_falls_through_to_mysql_default(): void
    {
        // Defensive default — an unknown engine string still produces
        // a usable block rather than throwing or returning empty.
        $env = DatabaseConnectionEnv::forEngine('something-weird', [
            'name' => 'a', 'username' => 'b', 'password' => 'c',
        ]);

        $this->assertStringContainsString('DB_CONNECTION=mysql', $env);
    }

    public function test_block_terminates_with_newline(): void
    {
        // Pipeline appends more lines after this block; trailing newline
        // ensures clean concatenation without merging variables.
        foreach (['sqlite3', 'mysql84', 'postgres17', 'mariadb114'] as $engine) {
            $env = DatabaseConnectionEnv::forEngine($engine, ['name' => 'a', 'username' => 'b', 'password' => 'c']);
            $this->assertStringEndsWith("\n", $env, "engine: {$engine}");
        }
    }
}
