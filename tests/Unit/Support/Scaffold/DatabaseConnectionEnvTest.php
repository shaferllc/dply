<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Scaffold\DatabaseConnectionEnvTest;

use App\Support\Scaffold\DatabaseConnectionEnv;

test('sqlite emits minimal block with default path', function () {
    $env = DatabaseConnectionEnv::forEngine('sqlite3', []);

    expect($env)->toBe("DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n");
});
test('sqlite uses provided absolute path', function () {
    $env = DatabaseConnectionEnv::forEngine('sqlite3', [
        'sqlite_path' => '/home/dply/sites/example/database/database.sqlite',
    ]);

    $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
    $this->assertStringContainsString('DB_DATABASE=/home/dply/sites/example/database/database.sqlite', $env);

    // SQLite has no credentials concept — those keys MUST not appear.
    $this->assertStringNotContainsString('DB_HOST', $env);
    $this->assertStringNotContainsString('DB_USERNAME', $env);
    $this->assertStringNotContainsString('DB_PASSWORD', $env);
});
test('mysql block uses default host and port', function () {
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
});
test('mysql variants all route to mysql block', function () {
    // Engine ID variants (mysql57 / mysql80 / mysql84 / plain mysql)
    // all use the mysql driver — the wizard's variant choice
    // matters at install time, not at .env time.
    foreach (['mysql', 'mysql57', 'mysql80', 'mysql84'] as $variant) {
        $env = DatabaseConnectionEnv::forEngine($variant, ['name' => 'a', 'username' => 'b', 'password' => 'c']);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $env, "variant: {$variant}");
    }
});
test('mariadb uses dedicated driver', function () {
    $env = DatabaseConnectionEnv::forEngine('mariadb114', [
        'name' => 'myapp', 'username' => 'u', 'password' => 'p',
    ]);

    $this->assertStringContainsString('DB_CONNECTION=mariadb', $env);
    $this->assertStringContainsString('DB_PORT=3306', $env);
});
test('postgres uses pgsql driver and 5432 port', function () {
    $env = DatabaseConnectionEnv::forEngine('postgres17', [
        'name' => 'myapp', 'username' => 'u', 'password' => 'p',
    ]);

    $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);
    $this->assertStringContainsString('DB_PORT=5432', $env);
});
test('unknown engine falls through to mysql default', function () {
    // Defensive default — an unknown engine string still produces
    // a usable block rather than throwing or returning empty.
    $env = DatabaseConnectionEnv::forEngine('something-weird', [
        'name' => 'a', 'username' => 'b', 'password' => 'c',
    ]);

    $this->assertStringContainsString('DB_CONNECTION=mysql', $env);
});
test('block terminates with newline', function () {
    // Pipeline appends more lines after this block; trailing newline
    // ensures clean concatenation without merging variables.
    foreach (['sqlite3', 'mysql84', 'postgres17', 'mariadb114'] as $engine) {
        $env = DatabaseConnectionEnv::forEngine($engine, ['name' => 'a', 'username' => 'b', 'password' => 'c']);
        expect($env)->toEndWith("\n", "engine: {$engine}");
    }
});
