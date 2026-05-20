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
