<?php


namespace Tests\Unit\DeployHookRunnerTimeoutTest;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Sites\DeployHookRunner;
use Tests\Support\FakeRemoteShell;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('passes hook timeout to remote shell', function () {
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

    expect($shell->execCalls)->not->toBeEmpty();
    expect($shell->execCalls[0][1])->toBe(222);
});