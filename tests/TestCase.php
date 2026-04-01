<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadSqliteSchemaDumpWhenNeeded();

        $this->withoutVite();

        // Avoid blocking Livewire tests on SSH; tests that assert queued manage jobs opt in explicitly.
        config(['server_manage.queue_remote_tasks' => false]);
    }

    protected function loadSqliteSchemaDumpWhenNeeded(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        if (config('database.connections.sqlite.database') !== ':memory:') {
            return;
        }

        if (Schema::hasTable('users')) {
            return;
        }

        $schemaPath = database_path('schema/sqlite-schema.sql');
        if (! is_file($schemaPath)) {
            return;
        }

        DB::unprepared(file_get_contents($schemaPath) ?: '');
    }
}
