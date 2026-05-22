<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Deploy\ServerlessDeployHookRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ServerlessDeployHookRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function hook(Site $site, string $phase, int $order, string $script): void
    {
        SiteDeployHook::query()->create([
            'site_id' => $site->id,
            'phase' => $phase,
            'sort_order' => $order,
            'script' => $script,
            'timeout_seconds' => 60,
        ]);
    }

    public function test_it_runs_hooks_in_sort_order_and_returns_their_output(): void
    {
        $site = Site::factory()->create();
        $this->hook($site, SiteDeployHook::PHASE_AFTER_CLONE, 1, 'echo second-hook');
        $this->hook($site, SiteDeployHook::PHASE_AFTER_CLONE, 0, 'echo first-hook');

        $output = app(ServerlessDeployHookRunner::class)
            ->runPhase($site, SiteDeployHook::PHASE_AFTER_CLONE, sys_get_temp_dir());

        $this->assertStringContainsString('first-hook', $output);
        $this->assertStringContainsString('second-hook', $output);
        $this->assertLessThan(strpos($output, 'second-hook'), strpos($output, 'first-hook'));
    }

    public function test_a_failing_hook_aborts_with_an_exception(): void
    {
        $site = Site::factory()->create();
        $this->hook($site, SiteDeployHook::PHASE_BEFORE_CLONE, 0, 'echo about-to-fail; exit 3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exit 3/');

        app(ServerlessDeployHookRunner::class)
            ->runPhase($site, SiteDeployHook::PHASE_BEFORE_CLONE, sys_get_temp_dir());
    }

    public function test_a_phase_with_no_hooks_returns_an_empty_string(): void
    {
        $site = Site::factory()->create();

        $this->assertSame('', app(ServerlessDeployHookRunner::class)
            ->runPhase($site, SiteDeployHook::PHASE_AFTER_ACTIVATE, sys_get_temp_dir()));
    }

    public function test_hooks_run_in_the_given_working_directory(): void
    {
        $site = Site::factory()->create();
        $this->hook($site, SiteDeployHook::PHASE_AFTER_CLONE, 0, 'pwd');

        $dir = sys_get_temp_dir();
        $output = app(ServerlessDeployHookRunner::class)
            ->runPhase($site, SiteDeployHook::PHASE_AFTER_CLONE, $dir);

        $this->assertStringContainsString(rtrim($dir, '/'), $output);
    }
}
