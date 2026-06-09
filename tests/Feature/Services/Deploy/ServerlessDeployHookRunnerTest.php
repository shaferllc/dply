<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessDeployHookRunnerTest;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Deploy\ServerlessDeployHookRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

uses(RefreshDatabase::class);

function hook(Site $site, string $phase, int $order, string $script): void
{
    SiteDeployHook::query()->create([
        'site_id' => $site->id,
        'phase' => $phase,
        'sort_order' => $order,
        'script' => $script,
        'timeout_seconds' => 60,
    ]);
}
test('it runs hooks in sort order and returns their output', function () {
    $site = Site::factory()->create();
    hook($site, SiteDeployHook::PHASE_AFTER_CLONE, 1, 'echo second-hook');
    hook($site, SiteDeployHook::PHASE_AFTER_CLONE, 0, 'echo first-hook');

    $output = app(ServerlessDeployHookRunner::class)
        ->runPhase($site, SiteDeployHook::PHASE_AFTER_CLONE, sys_get_temp_dir());

    $this->assertStringContainsString('first-hook', $output);
    $this->assertStringContainsString('second-hook', $output);
    expect(strpos($output, 'first-hook'))->toBeLessThan(strpos($output, 'second-hook'));
});
test('a failing hook aborts with an exception', function () {
    $site = Site::factory()->create();
    hook($site, SiteDeployHook::PHASE_BEFORE_CLONE, 0, 'echo about-to-fail; exit 3');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessageMatches('/exit 3/');

    app(ServerlessDeployHookRunner::class)
        ->runPhase($site, SiteDeployHook::PHASE_BEFORE_CLONE, sys_get_temp_dir());
});
test('a phase with no hooks returns an empty string', function () {
    $site = Site::factory()->create();

    expect(app(ServerlessDeployHookRunner::class)
        ->runPhase($site, SiteDeployHook::PHASE_AFTER_ACTIVATE, sys_get_temp_dir()))->toBe('');
});
test('hooks run in the given working directory', function () {
    $site = Site::factory()->create();
    hook($site, SiteDeployHook::PHASE_AFTER_CLONE, 0, 'pwd');

    $dir = sys_get_temp_dir();
    $output = app(ServerlessDeployHookRunner::class)
        ->runPhase($site, SiteDeployHook::PHASE_AFTER_CLONE, $dir);

    $this->assertStringContainsString(rtrim($dir, '/'), $output);
});
