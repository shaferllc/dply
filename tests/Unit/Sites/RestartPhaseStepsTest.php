<?php

declare(strict_types=1);

namespace Tests\Unit\Sites\RestartPhaseStepsTest;

use App\Models\SiteDeployStep;

test('restart-type steps default to the post-activate restart phase', function () {
    expect(SiteDeployStep::defaultPhaseFor('artisan_queue_restart'))->toBe('restart');
    expect(SiteDeployStep::defaultPhaseFor('artisan_horizon_terminate'))->toBe('restart');
});

test('other artisan steps keep their phases', function () {
    expect(SiteDeployStep::defaultPhaseFor('artisan_migrate'))->toBe('release');
    expect(SiteDeployStep::defaultPhaseFor('artisan_optimize'))->toBe('release');
    expect(SiteDeployStep::defaultPhaseFor('composer_install'))->toBe('build');
});

test('restart is now an author-able user phase', function () {
    expect(SiteDeployStep::userPhases())->toContain('restart');
});

test('queue:restart is guarded on the command existing', function () {
    $cmd = (new SiteDeployStep(['step_type' => 'artisan_queue_restart']))->commandFor();

    expect($cmd)->toContain('php artisan list');
    expect($cmd)->toContain('queue:restart');
    // Skips cleanly rather than failing the deploy.
    expect($cmd)->toContain('skipping');
});

test('horizon:terminate only runs when laravel/horizon is installed', function () {
    $cmd = (new SiteDeployStep(['step_type' => 'artisan_horizon_terminate']))->commandFor();

    expect($cmd)->toContain('php artisan list');
    expect($cmd)->toContain('grep -q "horizon:terminate"');
    expect($cmd)->toContain('not installed');
    // Retains the post-restart supervisor verification.
    expect($cmd)->toContain('did not restart after horizon:terminate');
});

test('queue:restart / horizon:terminate are no longer release-phase types', function () {
    expect(SiteDeployStep::RELEASE_STEP_TYPES)->not->toContain('artisan_queue_restart');
    expect(SiteDeployStep::RELEASE_STEP_TYPES)->not->toContain('artisan_horizon_terminate');
    expect(SiteDeployStep::RESTART_STEP_TYPES)->toBe(['artisan_queue_restart', 'artisan_horizon_terminate']);
});
