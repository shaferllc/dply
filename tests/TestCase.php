<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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

        parent::tearDown();
    }
}
