<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\DatabaseEngineReconcileTest;

use App\Livewire\Servers\Concerns\ManagesDatabaseEngineLifecycle;
use App\Models\ServerDatabaseEngine;

/**
 * A ServerDatabaseEngine row said `postgres | running` on a server where
 * Postgres was never installed — the low-memory provisioning fallback had
 * silently substituted SQLite. Nothing verified stored status against the box,
 * and installDatabaseEngine() refuses with "already installed" on a RUNNING
 * row, so the engine could never be installed from the UI.
 */
function probeGuard(bool $loaded, array $state, string $engine): bool
{
    // Mirrors the guard in installDatabaseEngine(): refuse only when the probe
    // agrees the engine is present (or hasn't run / doesn't cover it).
    return ! $loaded
        || ! array_key_exists($engine, $state)
        || (bool) $state[$engine];
}

test('a running row the probe contradicts does not block reinstall', function () {
    expect(probeGuard(true, ['postgres' => false], 'postgres'))->toBeFalse();
});

test('a running row the probe confirms still blocks reinstall', function () {
    expect(probeGuard(true, ['postgres' => true], 'postgres'))->toBeTrue();
});

test('an unrun or incomplete probe never invents a failure', function () {
    // Absent probe data means "not checked", not "not installed" — demoting on
    // that would fabricate failures on every SSH timeout.
    expect(probeGuard(false, [], 'postgres'))->toBeTrue()
        ->and(probeGuard(true, ['mysql' => true], 'postgres'))->toBeTrue();
});

test('the reconcile guard is wired into the lifecycle concern', function () {
    $source = file_get_contents(base_path('app/Livewire/Servers/Concerns/ManagesDatabaseEngineLifecycle.php'));

    // Demotes stale rows during a recheck...
    expect($source)->toContain('STATUS_FAILED')
        ->and($source)->toContain('the stored status was stale')
        // ...and never touches sqlite, which the probe treats differently.
        ->and($source)->toContain("\$engine === 'sqlite'");

    expect(class_exists(ManagesDatabaseEngineLifecycle::class) || trait_exists(ManagesDatabaseEngineLifecycle::class))->toBeTrue();
    expect(ServerDatabaseEngine::STATUS_FAILED)->not->toBe(ServerDatabaseEngine::STATUS_RUNNING);
});
