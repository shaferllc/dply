<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Sites\DeployHookRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRemoteShell;
use Tests\TestCase;

class DeployHookRunnerTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_hook_timeout_to_remote_shell(): void
    {
        config(['dply.default_deploy_hook_timeout_seconds' => 111]);

        $site = Site::factory()->create();
        SiteDeployHook::query()->create([
            'site_id' => $site->id,
            'phase' => SiteDeployHook::PHASE_BEFORE_CLONE,
            'script' => 'echo hi',
            'sort_order' => 0,
            'timeout_seconds' => 222,
        ]);

        $shell = new FakeRemoteShell;
        $runner = new DeployHookRunner;
        $runner->runPhase($shell, $site->fresh(), SiteDeployHook::PHASE_BEFORE_CLONE, '/tmp');

        $this->assertNotEmpty($shell->execCalls);
        $this->assertSame(222, $shell->execCalls[0][1]);
    }
}
