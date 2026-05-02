<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteSettingsProcessesEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_site_process_creates_worker_row_and_dispatches_systemd_job(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('new_site_process_type', SiteProcess::TYPE_WORKER)
            ->set('new_site_process_name', 'sidekiq')
            ->set('new_site_process_command', 'bundle exec sidekiq')
            ->call('addSiteProcess')
            ->assertSet('new_site_process_name', '')
            ->assertSet('new_site_process_command', '');

        $proc = $site->processes()->where('name', 'sidekiq')->first();
        $this->assertNotNull($proc);
        $this->assertSame(SiteProcess::TYPE_WORKER, $proc->type);
        $this->assertSame('bundle exec sidekiq', $proc->command);
        $this->assertTrue($proc->is_active);

        Queue::assertPushed(ProvisionSiteSystemdUnitsJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_add_site_process_rejects_reserved_web_name(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('new_site_process_name', 'web')
            ->set('new_site_process_command', 'something else')
            ->call('addSiteProcess')
            ->assertHasErrors(['new_site_process_name']);

        $this->assertSame(0, $site->processes()->where('name', 'web')->where('type', SiteProcess::TYPE_WORKER)->count());
    }

    public function test_add_site_process_rejects_duplicate_name(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('new_site_process_name', 'worker')
            ->set('new_site_process_command', 'something different')
            ->call('addSiteProcess')
            ->assertHasErrors(['new_site_process_name']);

        $this->assertSame(1, $site->processes()->where('name', 'worker')->count());
    }

    public function test_add_site_process_validates_name_pattern(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('new_site_process_name', 'bad name with spaces')
            ->set('new_site_process_command', 'echo hi')
            ->call('addSiteProcess')
            ->assertHasErrors(['new_site_process_name']);
    }

    public function test_remove_site_process_deletes_a_worker(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'celery',
            'command' => 'celery -A app worker',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('removeSiteProcess', $process->id);

        $this->assertSame(0, $site->processes()->where('name', 'celery')->count());

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\TearDownSiteSystemdUnitJob::class, function ($job) use ($site) {
            return $job->siteId === $site->id
                && str_contains($job->unitName, 'celery.service');
        });
    }

    public function test_remove_site_process_skips_teardown_job_for_php_site(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makePhpSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'horizon',
            'command' => 'php artisan horizon',
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('removeSiteProcess', $process->id);

        Queue::assertNotPushed(\App\Jobs\TearDownSiteSystemdUnitJob::class);
    }

    public function test_remove_site_process_refuses_to_delete_web_row(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $web = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();
        $this->assertNotNull($web, 'Site::created hook should have made a web row');

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('removeSiteProcess', $web->id);

        $this->assertNotNull($site->processes()->where('id', $web->id)->first());
    }

    public function test_toggle_active_flips_inactive_to_active(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'sidekiq',
            'command' => 'bundle exec sidekiq',
            'is_active' => false,
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('toggleSiteProcessActive', $process->id);

        $this->assertTrue((bool) $process->refresh()->is_active);
    }

    public function test_toggle_active_refuses_to_deactivate_web_row(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $web = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();
        $this->assertNotNull($web);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('toggleSiteProcessActive', $web->id);

        $this->assertTrue((bool) $web->refresh()->is_active);
    }

    public function test_set_scale_updates_process_and_dispatches_systemd_job(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
            'scale' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('setSiteProcessScale', $process->id, 3);

        $this->assertSame(3, (int) $process->refresh()->scale);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProvisionSiteSystemdUnitsJob::class);
    }

    public function test_set_scale_rejects_out_of_bounds_values(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
            'scale' => 1,
        ]);

        // Below minimum.
        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('setSiteProcessScale', $process->id, 0);
        $this->assertSame(1, (int) $process->refresh()->scale);

        // Above maximum.
        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('setSiteProcessScale', $process->id, 100);
        $this->assertSame(1, (int) $process->refresh()->scale);
    }

    public function test_restart_site_process_calls_provisioner(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'sidekiq',
            'command' => 'bundle exec sidekiq',
        ]);

        $provisioner = \Mockery::mock(\App\Services\Sites\SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('restartUnit')->once()->andReturn('');
        $this->app->instance(\App\Services\Sites\SiteSystemdProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('restartSiteProcess', $process->id)
            ->assertHasNoErrors();
    }

    public function test_restart_refuses_web_process(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeNodeSite();
        $web = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();

        $provisioner = \Mockery::mock(\App\Services\Sites\SiteSystemdProvisioner::class);
        $provisioner->shouldNotReceive('restartUnit');
        $this->app->instance(\App\Services\Sites\SiteSystemdProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('restartSiteProcess', $web->id);
    }

    public function test_restart_refuses_php_site(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makePhpSite();
        $process = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'horizon',
            'command' => 'php artisan horizon',
        ]);

        $provisioner = \Mockery::mock(\App\Services\Sites\SiteSystemdProvisioner::class);
        $provisioner->shouldNotReceive('restartUnit');
        $this->app->instance(\App\Services\Sites\SiteSystemdProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('restartSiteProcess', $process->id);
    }

    public function test_does_not_dispatch_systemd_job_for_php_site(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makePhpSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('new_site_process_type', SiteProcess::TYPE_WORKER)
            ->set('new_site_process_name', 'horizon')
            ->set('new_site_process_command', 'php artisan horizon')
            ->call('addSiteProcess');

        Queue::assertNotPushed(ProvisionSiteSystemdUnitsJob::class);
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeNodeSite(): array
    {
        $user = $this->seedUser();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => ['webserver' => 'nginx'],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        return [$user, $server, $site];
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makePhpSite(): array
    {
        $user = $this->seedUser();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => ['webserver' => 'nginx'],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'runtime' => 'php',
            'runtime_version' => '8.4',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        return [$user, $server, $site];
    }

    private function seedUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
