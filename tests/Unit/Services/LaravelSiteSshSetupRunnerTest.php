<?php

namespace Tests\Unit\Services\LaravelSiteSshSetupRunnerTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\User;
use App\Services\Sites\LaravelSiteSshSetupRunner;
use App\Services\Sites\SiteDeployPipelineCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function laravelVmSite(array $siteMeta = [], array $serverAttrs = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ], $serverAttrs));

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => array_merge([
                    'framework' => 'laravel',
                    'language' => 'php',
                ], $siteMeta),
            ],
        ],
    ]);
}

test('command for composer uses cd and escapes deploy directory', function () {
    $site = laravelVmSite();
    $site->update([
        'repository_path' => "/var/www/o'brien/app",
        'deploy_strategy' => 'simple',
    ]);

    $runner = new LaravelSiteSshSetupRunner;
    $cmd = $runner->commandFor($site, LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL);

    $this->assertStringContainsString('cd ', $cmd);
    $this->assertStringContainsString('composer install --no-dev', $cmd);
    $this->assertStringContainsString(escapeshellarg($site->effectiveEnvDirectory()), $cmd);
});

test('allowed actions include octane when package detected', function () {
    $site = laravelVmSite(['laravel_octane' => true]);
    $runner = new LaravelSiteSshSetupRunner;

    $actions = $runner->allowedActions($site);

    expect($actions)->toContain(LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL);
    expect($actions)->toContain(LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE);
    expect($actions)->toContain(LaravelSiteSshSetupRunner::ACTION_OCTANE_INSTALL);
    expect($actions)->not->toContain(LaravelSiteSshSetupRunner::ACTION_REVERB_INSTALL);
});

test('allowed actions include octane and reverb when both packages detected', function () {
    $site = laravelVmSite(['laravel_octane' => true, 'laravel_reverb' => true]);
    $runner = new LaravelSiteSshSetupRunner;

    $actions = $runner->allowedActions($site);

    expect($actions)->toBe([
        SiteDeployStep::TYPE_COMPOSER_INSTALL,
        SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
        SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL,
        SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL,
    ]);
});

test('assert action allowed rejects unknown action', function () {
    $site = laravelVmSite();
    $runner = new LaravelSiteSshSetupRunner;

    $this->expectException(\InvalidArgumentException::class);
    $runner->assertActionAllowed($site, 'not_a_real_action');
});

test('artisan optimize command and timeout', function () {
    $site = laravelVmSite();
    $runner = new LaravelSiteSshSetupRunner;
    $cmd = $runner->commandFor($site, LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE);

    $this->assertStringContainsString('php artisan optimize --no-interaction', $cmd);
    expect('php artisan optimize --no-interaction')->toBe(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, ''));
    expect($runner->timeoutSecondsFor(LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE))->toBe(300);
});

test('ssh command inner fragment matches deploy pipeline for each action', function () {
    $site = laravelVmSite(['laravel_octane' => true, 'laravel_reverb' => true]);
    $runner = new LaravelSiteSshSetupRunner;

    foreach ($runner->allowedActions($site) as $action) {
        $full = $runner->commandFor($site, $action);
        $inner = SiteDeployPipelineCommands::fragmentFor($action, '');
        expect($inner)->not->toBeNull();
        $this->assertStringContainsString($inner, $full);
    }
});

test('site can run laravel ssh setup requires vm ready ssh and laravel', function () {
    $site = laravelVmSite();
    expect($site->fresh()->canRunLaravelSshSetupActions())->toBeTrue();

    $site->server->update(['ssh_private_key' => null]);
    expect($site->fresh()->canRunLaravelSshSetupActions())->toBeFalse();

    $site = laravelVmSite();
    $site->server->update(['status' => Server::STATUS_PENDING]);
    expect($site->fresh()->canRunLaravelSshSetupActions())->toBeFalse();

    $site = laravelVmSite();
    $site->update([
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'symfony',
                    'language' => 'php',
                ],
            ],
        ],
    ]);
    expect($site->fresh()->canRunLaravelSshSetupActions())->toBeFalse();
});

test('can run laravel ssh setup false when effective deploy directory is blank', function () {
    $site = laravelVmSite();
    $site->update([
        'repository_path' => null,
        'document_root' => ' ',
    ]);

    expect($site->fresh()->canRunLaravelSshSetupActions())->toBeFalse();
});
