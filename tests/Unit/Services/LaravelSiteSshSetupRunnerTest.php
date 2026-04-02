<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\User;
use App\Services\Sites\LaravelSiteSshSetupRunner;
use App\Services\Sites\SiteDeployPipelineCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaravelSiteSshSetupRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function laravelVmSite(array $siteMeta = [], array $serverAttrs = []): Site
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

    public function test_command_for_composer_uses_cd_and_escapes_deploy_directory(): void
    {
        $site = $this->laravelVmSite();
        $site->update([
            'repository_path' => "/var/www/o'brien/app",
            'deploy_strategy' => 'simple',
        ]);

        $runner = new LaravelSiteSshSetupRunner;
        $cmd = $runner->commandFor($site, LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL);

        $this->assertStringContainsString('cd ', $cmd);
        $this->assertStringContainsString('composer install --no-dev', $cmd);
        $this->assertStringContainsString(escapeshellarg($site->effectiveEnvDirectory()), $cmd);
    }

    public function test_allowed_actions_include_octane_when_package_detected(): void
    {
        $site = $this->laravelVmSite(['laravel_octane' => true]);
        $runner = new LaravelSiteSshSetupRunner;

        $actions = $runner->allowedActions($site);

        $this->assertContains(LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL, $actions);
        $this->assertContains(LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE, $actions);
        $this->assertContains(LaravelSiteSshSetupRunner::ACTION_OCTANE_INSTALL, $actions);
        $this->assertNotContains(LaravelSiteSshSetupRunner::ACTION_REVERB_INSTALL, $actions);
    }

    public function test_allowed_actions_include_octane_and_reverb_when_both_packages_detected(): void
    {
        $site = $this->laravelVmSite(['laravel_octane' => true, 'laravel_reverb' => true]);
        $runner = new LaravelSiteSshSetupRunner;

        $actions = $runner->allowedActions($site);

        $this->assertSame([
            SiteDeployStep::TYPE_COMPOSER_INSTALL,
            SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
            SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL,
            SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL,
        ], $actions);
    }

    public function test_assert_action_allowed_rejects_unknown_action(): void
    {
        $site = $this->laravelVmSite();
        $runner = new LaravelSiteSshSetupRunner;

        $this->expectException(\InvalidArgumentException::class);
        $runner->assertActionAllowed($site, 'not_a_real_action');
    }

    public function test_artisan_optimize_command_and_timeout(): void
    {
        $site = $this->laravelVmSite();
        $runner = new LaravelSiteSshSetupRunner;
        $cmd = $runner->commandFor($site, LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE);

        $this->assertStringContainsString('php artisan optimize --no-interaction', $cmd);
        $this->assertSame(
            SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, ''),
            'php artisan optimize --no-interaction'
        );
        $this->assertSame(300, $runner->timeoutSecondsFor(LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE));
    }

    public function test_ssh_command_inner_fragment_matches_deploy_pipeline_for_each_action(): void
    {
        $site = $this->laravelVmSite(['laravel_octane' => true, 'laravel_reverb' => true]);
        $runner = new LaravelSiteSshSetupRunner;

        foreach ($runner->allowedActions($site) as $action) {
            $full = $runner->commandFor($site, $action);
            $inner = SiteDeployPipelineCommands::fragmentFor($action, '');
            $this->assertNotNull($inner);
            $this->assertStringContainsString($inner, $full);
        }
    }

    public function test_site_can_run_laravel_ssh_setup_requires_vm_ready_ssh_and_laravel(): void
    {
        $site = $this->laravelVmSite();
        $this->assertTrue($site->fresh()->canRunLaravelSshSetupActions());

        $site->server->update(['ssh_private_key' => null]);
        $this->assertFalse($site->fresh()->canRunLaravelSshSetupActions());

        $site = $this->laravelVmSite();
        $site->server->update(['status' => Server::STATUS_PENDING]);
        $this->assertFalse($site->fresh()->canRunLaravelSshSetupActions());

        $site = $this->laravelVmSite();
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
        $this->assertFalse($site->fresh()->canRunLaravelSshSetupActions());
    }

    public function test_can_run_laravel_ssh_setup_false_when_effective_deploy_directory_is_blank(): void
    {
        $site = $this->laravelVmSite();
        $site->update([
            'repository_path' => null,
            'document_root' => ' ',
        ]);

        $this->assertFalse($site->fresh()->canRunLaravelSshSetupActions());
    }
}
