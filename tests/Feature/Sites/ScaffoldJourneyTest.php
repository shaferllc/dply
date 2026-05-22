<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Livewire\Sites\ScaffoldJourney;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Scaffold\PlaceholderDnsManager;
use App\Services\Scaffold\ScaffoldStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ScaffoldJourneyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a stub PlaceholderDnsManager into the container so Livewire's
     * injection on retry() finds something safe — we don't want any
     * real DNS-API or nip.io HTTP calls firing from these tests.
     */
    private function stubPlaceholderDns(): PlaceholderDnsManager
    {
        $mock = Mockery::mock(PlaceholderDnsManager::class);
        $mock->shouldReceive('release')->andReturnNull();
        $mock->shouldReceive('assign')->andReturn([
            'hostname' => 'stub.test', 'zone' => null, 'record_id' => null, 'source' => 'nip.io',
        ]);
        app()->instance(PlaceholderDnsManager::class, $mock);

        return $mock;
    }

    private function makeSite(string $status, array $scaffoldMeta = [], string $userRole = 'admin'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $userRole]);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => $status,
            'meta' => ['scaffold' => array_merge([
                'framework' => 'wordpress',
                'admin_email' => 'admin@example.com',
                'steps' => [
                    ScaffoldStep::pending('prereqs', 'Verify prerequisites'),
                    ScaffoldStep::pending('db_create', 'Create database'),
                ],
            ], $scaffoldMeta)],
        ]);

        return [$user, $server, $site];
    }

    public function test_running_pipeline_renders_step_list(): void
    {
        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLDING);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->assertSee('Verify prerequisites')
            ->assertSee('Create database')
            ->assertSee('Auto-refreshing every 2 seconds');
    }

    public function test_failed_pipeline_shows_retry_button_when_under_attempt_cap(): void
    {
        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLD_FAILED, [
            'attempt_count' => 1,
            'steps' => [
                ['key' => 'prereqs', 'label' => 'Verify prerequisites', 'state' => ScaffoldStep::STATE_FAILED, 'error' => 'wp-cli unavailable'],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->assertSee('Scaffold failed')
            ->assertSee('Retry scaffold')
            ->assertSee('wp-cli unavailable');
    }

    public function test_failed_pipeline_after_three_attempts_offers_delete_only(): void
    {
        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLD_FAILED, [
            'attempt_count' => 3,
            'steps' => [
                ['key' => 'wp_install', 'label' => 'wp install', 'state' => ScaffoldStep::STATE_FAILED, 'error' => 'oops'],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->assertDontSee('Retry scaffold')
            ->assertSee('Delete site and start fresh');
    }

    public function test_retry_dispatches_pipeline_and_resets_state(): void
    {
        Bus::fake();
        $this->stubPlaceholderDns();
        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLD_FAILED, [
            'attempt_count' => 1,
            'admin_password' => encrypt('old-password'),
            'steps' => [
                ['key' => 'prereqs', 'state' => ScaffoldStep::STATE_FAILED],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->call('retry');

        $site->refresh();
        $this->assertSame(Site::STATUS_SCAFFOLDING, $site->status);
        $this->assertSame(2, $site->meta['scaffold']['attempt_count']);
        $this->assertSame([], $site->meta['scaffold']['steps']);
        $this->assertArrayNotHasKey('admin_password', $site->meta['scaffold']);

        Bus::assertDispatched(RunWordPressScaffoldJob::class,
            fn ($job) => $job->siteId === $site->id);
    }

    public function test_retry_dispatches_laravel_job_for_laravel_framework(): void
    {
        Bus::fake();
        $this->stubPlaceholderDns();
        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLD_FAILED, [
            'framework' => 'laravel',
            'attempt_count' => 1,
            'steps' => [['key' => 'prereqs', 'state' => ScaffoldStep::STATE_FAILED]],
        ]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->call('retry');

        Bus::assertDispatched(RunLaravelScaffoldJob::class);
    }

    public function test_retry_releases_prior_placeholder_and_drops_site_domain_row(): void
    {
        Bus::fake();

        // Asserting mock — release must be called exactly once with this Site
        // before the new pipeline job is dispatched.
        $dns = Mockery::mock(PlaceholderDnsManager::class);
        $dns->shouldReceive('release')->once();
        $dns->shouldReceive('assign')->andReturn([
            'hostname' => 'unused.test', 'zone' => null, 'record_id' => null, 'source' => 'nip.io',
        ]);
        app()->instance(PlaceholderDnsManager::class, $dns);

        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLD_FAILED, [
            'framework' => 'wordpress',
            'attempt_count' => 1,
            'placeholder_dns' => ['hostname' => 'old-blog.198-51-100-1.nip.io', 'source' => 'nip.io'],
            'steps' => [['key' => 'wp_install', 'state' => ScaffoldStep::STATE_FAILED]],
        ]);
        // Pre-existing SiteDomain row from the failed first attempt.
        $site->domains()->create(['hostname' => 'old-blog.198-51-100-1.nip.io', 'is_primary' => true, 'www_redirect' => false]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->call('retry');

        $site->refresh();
        $this->assertSame(0, $site->domains()->where('hostname', 'old-blog.198-51-100-1.nip.io')->count(),
            'Stale SiteDomain row from prior attempt must be deleted to free the unique hostname constraint');
        Bus::assertDispatched(RunWordPressScaffoldJob::class);
    }

    public function test_retry_is_a_no_op_after_attempt_cap(): void
    {
        Bus::fake();
        $this->stubPlaceholderDns();
        [$user, $server, $site] = $this->makeSite(Site::STATUS_SCAFFOLD_FAILED, [
            'attempt_count' => 3,
            'steps' => [['key' => 'prereqs', 'state' => ScaffoldStep::STATE_FAILED]],
        ]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->call('retry');

        $site->refresh();
        $this->assertSame(Site::STATUS_SCAFFOLD_FAILED, $site->status);
        Bus::assertNotDispatched(RunWordPressScaffoldJob::class);
        Bus::assertNotDispatched(RunLaravelScaffoldJob::class);
    }

    public function test_completed_state_renders_password_reveal(): void
    {
        [$user, $server, $site] = $this->makeSite(Site::STATUS_PENDING, [
            'admin_password' => encrypt('hunter2!hunter2'),
            'steps' => [
                ['key' => 'prereqs', 'state' => ScaffoldStep::STATE_COMPLETED],
                ['key' => 'db_create', 'state' => ScaffoldStep::STATE_COMPLETED],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->assertSee('Scaffold complete')
            ->assertSee('Reveal password')
            ->assertDontSee('hunter2')
            ->call('revealPassword')
            ->assertSet('passwordRevealed', true)
            ->assertSee('hunter2');
    }

    public function test_member_role_cannot_reveal_password(): void
    {
        [$user, $server, $site] = $this->makeSite(Site::STATUS_PENDING, [
            'admin_password' => encrypt('not-for-members'),
            'steps' => [['key' => 'prereqs', 'state' => ScaffoldStep::STATE_COMPLETED]],
        ], userRole: 'member');

        Livewire::actingAs($user)
            ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
            ->call('revealPassword')
            ->assertSet('passwordRevealed', false)
            ->assertHasErrors('reveal');
    }

    public function test_404s_for_non_scaffolded_site(): void
    {
        [$user, $server, $site] = $this->makeSite(Site::STATUS_NGINX_ACTIVE);
        $site->meta = [];
        $site->save();

        $this->actingAs($user)
            ->get(route('sites.scaffold-journey', ['server' => $server, 'site' => $site->fresh()]))
            ->assertNotFound();
    }
}
