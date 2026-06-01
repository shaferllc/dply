<?php

namespace Tests;

use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Support\Servers\CacheServiceNetworkExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop Postgres composite types alongside tables when RefreshDatabase
     * triggers `migrate:fresh`. Without this, composite types auto-created
     * with each table linger after wipe and the *next* test run collides
     * with "duplicate key value violates unique constraint pg_type_typname_nsp_index".
     */
    protected bool $dropTypes = true;

    protected function setUp(): void
    {
        $this->guardAgainstDestructiveDatabaseTarget();

        parent::setUp();

        // Production Livewire actions (e.g. server log tailing) call set_time_limit()
        // with request budgets. That applies to the whole PHPUnit worker, so one
        // test can poison the remaining batch with a 90s cap.
        set_time_limit(0);

        $this->withoutVite();

        // Avoid blocking Livewire tests on SSH; tests that assert queued manage jobs opt in explicitly.
        config(['server_manage.queue_remote_tasks' => false]);

        foreach (class_uses_recursive(static::class) as $trait) {
            $hook = 'setUp'.class_basename($trait);
            if (method_exists($this, $hook)) {
                $this->{$hook}();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            $hook = 'tearDown'.class_basename($trait);
            if (method_exists($this, $hook)) {
                $this->{$hook}();
            }
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        try {
            DB::disconnect(config('database.default'));
        } catch (\Throwable) {
            // Best-effort cleanup only.
        }

        GetProviderCredentialsForServerType::flushMemo();
        CacheServiceNetworkExposure::flushManagedRuleMemo();

        parent::tearDown();
    }

    /**
     * RefreshDatabase runs migrate:fresh — refuse to run it against a non-test DB.
     */
    protected function guardAgainstDestructiveDatabaseTarget(): void
    {
        if (! $this->usesRefreshDatabase()) {
            return;
        }

        // Runs before parent::setUp() — use env vars, not config().
        $connection = (string) (getenv('DB_CONNECTION') ?: $_ENV['DB_CONNECTION'] ?? 'pgsql');
        $database = (string) (getenv('DB_DATABASE') ?: $_ENV['DB_DATABASE'] ?? '');

        if ($database === '' && $connection === 'pgsql') {
            $database = 'dply_testing';
        }

        $allowed = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) (getenv('DPLY_TESTING_DATABASES') ?: $_ENV['DPLY_TESTING_DATABASES'] ?? 'dply_testing')),
        )));

        if ($allowed === []) {
            $allowed = ['dply_testing'];
        }

        if (! in_array($database, $allowed, true)) {
            throw new \RuntimeException(sprintf(
                'Refusing to run RefreshDatabase tests against [%s] on connection [%s]. '
                .'Use a dedicated test database (default: dply_testing). '
                .'phpunit.xml sets DB_DATABASE=dply_testing — check .env DB_URL / DB_DATABASE overrides.',
                $database,
                $connection,
            ));
        }
    }

    protected function usesRefreshDatabase(): bool
    {
        return in_array(
            RefreshDatabase::class,
            class_uses_recursive(static::class),
            true,
        );
    }
}
