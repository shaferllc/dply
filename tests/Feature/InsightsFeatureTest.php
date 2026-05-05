<?php

namespace Tests\Feature;

use App\Jobs\RunServerInsightsJob;
use App\Livewire\Servers\WorkspaceInsights;
use App\Models\InsightFinding;
use App\Models\InsightSetting;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\ServerProvisionRun;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\InsightRunCoordinator;
use App\Services\Insights\InsightSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class InsightsFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Server}
     */
    protected function userWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '127.0.0.1',
        ]);

        return [$user, $server];
    }

    public function test_server_insights_page_renders_for_owner(): void
    {
        [$user, $server] = $this->userWithServer();

        $this->actingAs($user)
            ->get(route('servers.insights', $server))
            ->assertOk();
    }

    public function test_server_insights_page_shows_only_open_findings(): void
    {
        [$user, $server] = $this->userWithServer();

        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'cpu_ram_usage',
            'dedupe_hash' => 'open',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'High CPU',
            'body' => 'Visible finding',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'load_average_high',
            'dedupe_hash' => 'resolved',
            'status' => InsightFinding::STATUS_RESOLVED,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'Resolved load',
            'body' => 'Should not be shown',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now()->subMinute(),
            'resolved_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('servers.insights', $server))
            ->assertOk()
            ->assertSee('High CPU')
            ->assertDontSee('Resolved load')
            ->assertDontSee('Should not be shown');
    }

    public function test_server_overview_shows_open_insights_summary(): void
    {
        [$user, $server] = $this->userWithServer();

        $server->update([
            'setup_status' => Server::SETUP_STATUS_DONE,
            'health_status' => Server::HEALTH_REACHABLE,
        ]);

        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'metrics_missing_or_stale',
            'dedupe_hash' => 'missing',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'Server metrics are not arriving',
            'body' => 'No metrics snapshots have been stored.',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('servers.overview', $server))
            ->assertOk()
            ->assertSee('Insights')
            ->assertSee('1 open finding')
            // Individual finding titles are no longer rendered on the
            // overview's insights summary card — that detail moved to
            // /servers/{id}/insights as part of the dashboard refactor.
            ->assertSee('Open Insights');
    }

    public function test_save_settings_persists_enabled_map(): void
    {
        [$user, $server] = $this->userWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->set('enabled_map.cpu_ram_usage', false)
            ->set('tab', 'settings')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $row = InsightSetting::query()
            ->where('settingsable_type', $server->getMorphClass())
            ->where('settingsable_id', $server->getKey())
            ->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) ($row->enabled_map['cpu_ram_usage'] ?? true));
    }

    public function test_heartbeat_insight_creates_info_finding_when_enabled(): void
    {
        [$user, $server] = $this->userWithServer();
        $org = $server->organization;
        $this->assertNotNull($org);
        $repo = app(InsightSettingsRepository::class);
        $setting = $repo->forServer($server, $org);
        $map = $setting->enabled_map ?? [];
        $map['insights_pipeline_heartbeat'] = true;
        $setting->update(['enabled_map' => $map]);

        Bus::dispatchSync(new RunServerInsightsJob($server->id));

        $this->assertDatabaseHas('insight_findings', [
            'server_id' => $server->id,
            'insight_key' => 'insights_pipeline_heartbeat',
            'severity' => InsightFinding::SEVERITY_INFO,
            'status' => InsightFinding::STATUS_OPEN,
        ]);
    }

    public function test_run_server_insights_job_records_cpu_finding_when_high(): void
    {
        [$user, $server] = $this->userWithServer();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [
                'cpu_pct' => 95,
                'mem_pct' => 10,
                'disk_pct' => 10,
                'load_1m' => 0.5,
            ],
        ]);

        Bus::dispatchSync(new RunServerInsightsJob($server->id));

        $this->assertDatabaseHas('insight_findings', [
            'server_id' => $server->id,
            'insight_key' => 'cpu_ram_usage',
            'status' => InsightFinding::STATUS_OPEN,
        ]);
    }

    public function test_run_server_insights_job_records_missing_metrics_finding_when_no_snapshots_exist(): void
    {
        [$user, $server] = $this->userWithServer();

        // Pretend the monitoring guest script has been installed (push
        // token hash recorded). Without this signal the runner treats
        // missing snapshots as "user hasn't opted into monitoring yet"
        // and stays quiet — see MetricsMissingInsightRunner.
        $meta = is_array($server->meta ?? null) ? $server->meta : [];
        $meta['monitoring_guest_push_token_hash'] = hash('sha256', 'install-marker');
        $server->forceFill(['meta' => $meta])->save();

        Bus::dispatchSync(new RunServerInsightsJob($server->id));

        $this->assertDatabaseHas('insight_findings', [
            'server_id' => $server->id,
            'insight_key' => 'metrics_missing_or_stale',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
        ]);
    }

    public function test_run_server_insights_job_skips_missing_metrics_finding_when_monitoring_not_installed(): void
    {
        [$user, $server] = $this->userWithServer();

        Bus::dispatchSync(new RunServerInsightsJob($server->id));

        $this->assertDatabaseMissing('insight_findings', [
            'server_id' => $server->id,
            'insight_key' => 'metrics_missing_or_stale',
            'status' => InsightFinding::STATUS_OPEN,
        ]);
    }

    public function test_run_server_insights_job_records_health_check_url_missing_finding_for_hosting_server(): void
    {
        [$user, $server] = $this->userWithServer();

        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'status' => Site::STATUS_NGINX_ACTIVE,
            'ssl_status' => Site::SSL_ACTIVE,
        ]);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [
                'cpu_pct' => 10,
                'mem_pct' => 10,
                'disk_pct' => 10,
                'load_1m' => 0.5,
            ],
        ]);

        Bus::dispatchSync(new RunServerInsightsJob($server->id));

        $this->assertDatabaseHas('insight_findings', [
            'server_id' => $server->id,
            'insight_key' => 'health_check_url_missing',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_INFO,
        ]);
    }

    public function test_site_insights_page_renders(): void
    {
        [$user, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'status' => Site::STATUS_NGINX_ACTIVE,
            'ssl_status' => Site::SSL_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('sites.insights', [$server, $site]))
            ->assertOk();
    }

    public function test_acknowledge_finding_clears_banner_but_keeps_finding_in_list(): void
    {
        [$user, $server] = $this->userWithServer();

        $crit = InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'cpu_ram_usage',
            'dedupe_hash' => 'crit',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_CRITICAL,
            'title' => 'Critical CPU saturation',
            'body' => null,
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server]);

        $component
            ->assertViewHas('bannerFindings', fn ($c) => $c->count() === 1 && $c->first()->id === $crit->id)
            ->assertViewHas('findings', fn ($c) => $c->where('id', $crit->id)->count() === 1);

        $component->call('acknowledgeFinding', $crit->id);

        $crit->refresh();
        $this->assertNotNull($crit->acknowledged_at);
        $this->assertSame($user->id, $crit->acknowledged_by_user_id);

        $component->assertViewHas('bannerFindings', fn ($c) => $c->isEmpty())
            ->assertViewHas('findings', fn ($c) => $c->where('id', $crit->id)->count() === 1);
    }

    public function test_findings_are_ordered_by_severity_then_recency(): void
    {
        [$user, $server] = $this->userWithServer();

        $info = InsightFinding::query()->create([
            'server_id' => $server->id, 'site_id' => null, 'team_id' => null,
            'insight_key' => 'k1', 'dedupe_hash' => 'k1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_INFO,
            'title' => 'Info row', 'body' => null, 'meta' => [], 'correlation' => null,
            'detected_at' => now(), 'resolved_at' => null,
        ]);
        $warn = InsightFinding::query()->create([
            'server_id' => $server->id, 'site_id' => null, 'team_id' => null,
            'insight_key' => 'k2', 'dedupe_hash' => 'k2',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'Warn row', 'body' => null, 'meta' => [], 'correlation' => null,
            'detected_at' => now()->subMinutes(10), 'resolved_at' => null,
        ]);
        $crit = InsightFinding::query()->create([
            'server_id' => $server->id, 'site_id' => null, 'team_id' => null,
            'insight_key' => 'k3', 'dedupe_hash' => 'k3',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_CRITICAL,
            'title' => 'Crit row', 'body' => null, 'meta' => [], 'correlation' => null,
            'detected_at' => now()->subHour(), 'resolved_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->assertViewHas('findings', function ($c) use ($crit, $warn, $info) {
                $ids = $c->pluck('id')->all();

                return $ids === [$crit->id, $warn->id, $info->id];
            });
    }

    public function test_reopened_finding_clears_prior_acknowledgement(): void
    {
        [$user, $server] = $this->userWithServer();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => ['cpu_pct' => 95, 'mem_pct' => 10, 'disk_pct' => 10, 'load_1m' => 0.5],
        ]);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'cpu_ram_usage')
            ->first();
        $this->assertNotNull($finding);

        $finding->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
        ])->save();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now()->addMinute(),
            'payload' => ['cpu_pct' => 10, 'mem_pct' => 10, 'disk_pct' => 10, 'load_1m' => 0.5],
        ]);
        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_RESOLVED, $finding->status);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now()->addMinutes(2),
            'payload' => ['cpu_pct' => 95, 'mem_pct' => 10, 'disk_pct' => 10, 'load_1m' => 0.5],
        ]);
        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_OPEN, $finding->status);
        $this->assertNull($finding->acknowledged_at);
        $this->assertNull($finding->acknowledged_by_user_id);
    }

    public function test_insight_run_coordinator_resolves_when_condition_clears(): void
    {
        [$user, $server] = $this->userWithServer();

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [
                'cpu_pct' => 95,
                'mem_pct' => 10,
                'disk_pct' => 10,
                'load_1m' => 0.5,
            ],
        ]);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $open = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'cpu_ram_usage')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->first();
        $this->assertNotNull($open);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [
                'cpu_pct' => 10,
                'mem_pct' => 10,
                'disk_pct' => 10,
                'load_1m' => 0.5,
            ],
        ]);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $openAfter = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'cpu_ram_usage')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->first();
        $this->assertNull($openAfter);

        $resolved = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'cpu_ram_usage')
            ->where('status', InsightFinding::STATUS_RESOLVED)
            ->first();
        $this->assertNotNull($resolved);
    }

    public function test_coordinator_skips_runner_when_required_stack_tag_is_absent(): void
    {
        [, $server] = $this->userWithServer();
        $this->seedStackSummary($server, ['nginx', 'php-fpm']); // no mysql

        $this->registerStubInsight('stub_requires_mysql', requires: ['mysql']);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $this->assertSame(0, InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_requires_mysql')
            ->count(), 'Runner with requires=[mysql] must not execute on a Postgres-only stack');
    }

    public function test_coordinator_runs_runner_when_required_stack_tag_is_present(): void
    {
        [, $server] = $this->userWithServer();
        $this->seedStackSummary($server, ['nginx', 'mysql']);

        $this->registerStubInsight('stub_requires_mysql', requires: ['mysql']);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $this->assertSame(1, InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_requires_mysql')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count());
    }

    public function test_coordinator_fails_open_when_stack_summary_is_unknown(): void
    {
        [, $server] = $this->userWithServer();
        // No stack-summary artifact seeded → tagsFor() returns 'unknown'.

        $this->registerStubInsight('stub_requires_mysql', requires: ['mysql']);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $this->assertSame(1, InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_requires_mysql')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count(), 'Fresh server with no provision artifact must fail open and run gated runners');
    }

    public function test_recorder_persists_kind_from_candidate(): void
    {
        [, $server] = $this->userWithServer();

        $this->registerStubInsight('stub_suggestion', requires: [], kind: InsightFinding::KIND_SUGGESTION);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_suggestion')
            ->first();
        $this->assertNotNull($finding);
        $this->assertSame(InsightFinding::KIND_SUGGESTION, $finding->kind);
    }

    public function test_recorder_defaults_kind_to_problem_when_unspecified(): void
    {
        [, $server] = $this->userWithServer();

        $this->registerStubInsight('stub_problem_default', requires: []); // kind default

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_problem_default')
            ->first();
        $this->assertNotNull($finding);
        $this->assertSame(InsightFinding::KIND_PROBLEM, $finding->kind);
    }

    public function test_horizon_recommended_runner_emits_for_laravel_site_with_queue_worker_and_no_horizon(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        \App\Models\SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'slug' => 'site-queue',
            'program_type' => 'queue',
            'command' => 'php /var/www/app/artisan queue:work redis --tries=3',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => true,
            'env_vars' => null,
            'stdout_logfile' => '/var/log/supervisor/queue.log',
            'priority' => 999,
            'startsecs' => 1,
            'stopwaitsecs' => 30,
            'autorestart' => 'true',
            'redirect_stderr' => true,
        ]);

        $runner = app(\App\Services\Insights\Runners\HorizonRecommendedInsightRunner::class);
        $candidates = $runner->run($server, $site, []);

        $this->assertCount(1, $candidates);
        $c = $candidates[0];
        $this->assertSame('horizon_recommended', $c->insightKey);
        $this->assertSame(InsightFinding::KIND_SUGGESTION, $c->kind);
        $this->assertTrue($c->meta['signal']['has_supervisor_queue_worker']);
    }

    public function test_horizon_recommended_runner_skips_when_already_on_horizon(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                        'laravel_horizon' => true,
                    ],
                ],
            ],
        ]);

        \App\Models\SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'slug' => 'site-queue',
            'program_type' => 'queue',
            'command' => 'php /var/www/app/artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => true,
            'env_vars' => null,
            'stdout_logfile' => '/var/log/supervisor/queue.log',
            'priority' => 999,
            'startsecs' => 1,
            'stopwaitsecs' => 30,
            'autorestart' => 'true',
            'redirect_stderr' => true,
        ]);

        $runner = app(\App\Services\Insights\Runners\HorizonRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_horizon_recommended_runner_skips_when_no_queue_worker_present(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        // No SupervisorProgram seeded → no signal → suggestion does not fire.
        $runner = app(\App\Services\Insights\Runners\HorizonRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_horizon_recommended_runner_skips_inactive_queue_worker(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        \App\Models\SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'slug' => 'site-queue',
            'program_type' => 'queue',
            'command' => 'php /var/www/app/artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => false,
            'env_vars' => null,
            'stdout_logfile' => '/var/log/supervisor/queue.log',
            'priority' => 999,
            'startsecs' => 1,
            'stopwaitsecs' => 30,
            'autorestart' => 'true',
            'redirect_stderr' => true,
        ]);

        $runner = app(\App\Services\Insights\Runners\HorizonRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_octane_recommended_runner_emits_suggestion_for_busy_laravel_site_without_octane(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        // 12 samples with sustained load_1m=5 → above threshold 4.
        for ($i = 0; $i < 12; $i++) {
            ServerMetricSnapshot::query()->create([
                'server_id' => $server->id,
                'captured_at' => now()->subMinutes(12 - $i),
                'payload' => ['load_1m' => 5.0, 'cpu_pct' => 60, 'mem_pct' => 50, 'disk_pct' => 30],
            ]);
        }

        $runner = app(\App\Services\Insights\Runners\OctaneRecommendedInsightRunner::class);
        $candidates = $runner->run($server, $site, []);

        $this->assertCount(1, $candidates);
        $c = $candidates[0];
        $this->assertSame('octane_recommended', $c->insightKey);
        $this->assertSame(InsightFinding::KIND_SUGGESTION, $c->kind);
        $this->assertSame(InsightFinding::SEVERITY_INFO, $c->severity);
        $this->assertSame('site:'.$site->id, $c->dedupeHash);
        $this->assertArrayHasKey('signal', $c->meta);
        $this->assertSame(5.0, $c->meta['signal']['load_1m_avg']);
    }

    public function test_octane_recommended_runner_skips_when_site_already_uses_octane(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                        'laravel_octane' => true,
                    ],
                ],
            ],
        ]);

        for ($i = 0; $i < 12; $i++) {
            ServerMetricSnapshot::query()->create([
                'server_id' => $server->id,
                'captured_at' => now()->subMinutes(12 - $i),
                'payload' => ['load_1m' => 8.0, 'cpu_pct' => 80, 'mem_pct' => 50, 'disk_pct' => 30],
            ]);
        }

        $runner = app(\App\Services\Insights\Runners\OctaneRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_octane_recommended_runner_skips_non_laravel_sites(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'wordpress',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        for ($i = 0; $i < 12; $i++) {
            ServerMetricSnapshot::query()->create([
                'server_id' => $server->id,
                'captured_at' => now()->subMinutes(12 - $i),
                'payload' => ['load_1m' => 8.0, 'cpu_pct' => 80, 'mem_pct' => 50, 'disk_pct' => 30],
            ]);
        }

        $runner = app(\App\Services\Insights\Runners\OctaneRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_octane_recommended_runner_skips_when_load_below_threshold(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        // 12 samples but load is well below the 4.0 threshold.
        for ($i = 0; $i < 12; $i++) {
            ServerMetricSnapshot::query()->create([
                'server_id' => $server->id,
                'captured_at' => now()->subMinutes(12 - $i),
                'payload' => ['load_1m' => 0.5, 'cpu_pct' => 5, 'mem_pct' => 10, 'disk_pct' => 30],
            ]);
        }

        $runner = app(\App\Services\Insights\Runners\OctaneRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_octane_recommended_writes_finding_with_kind_suggestion_via_coordinator(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        for ($i = 0; $i < 12; $i++) {
            ServerMetricSnapshot::query()->create([
                'server_id' => $server->id,
                'captured_at' => now()->subMinutes(12 - $i),
                'payload' => ['load_1m' => 5.0, 'cpu_pct' => 60, 'mem_pct' => 50, 'disk_pct' => 30],
            ]);
        }

        // Stack-summary with php expected so the requires=['php'] gate passes.
        $this->seedStackSummary($server, ['nginx', 'php-fpm']);

        app(InsightRunCoordinator::class)->runForSite($site->fresh());

        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->where('insight_key', 'octane_recommended')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(InsightFinding::KIND_SUGGESTION, $finding->kind);
        $this->assertSame(InsightFinding::STATUS_OPEN, $finding->status);
        $this->assertSame(InsightFinding::SEVERITY_INFO, $finding->severity);
    }

    public function test_octane_recommended_runner_skips_when_too_few_samples(): void
    {
        [, $server] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);

        // Only 3 samples — below the default 12-sample minimum.
        for ($i = 0; $i < 3; $i++) {
            ServerMetricSnapshot::query()->create([
                'server_id' => $server->id,
                'captured_at' => now()->subMinutes(3 - $i),
                'payload' => ['load_1m' => 8.0, 'cpu_pct' => 80, 'mem_pct' => 50, 'disk_pct' => 30],
            ]);
        }

        $runner = app(\App\Services\Insights\Runners\OctaneRecommendedInsightRunner::class);
        $this->assertSame([], $runner->run($server, $site, []));
    }

    public function test_ignore_finding_marks_suggestion_as_ignored_and_records_user(): void
    {
        [$user, $server] = $this->userWithServer();

        $finding = InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'octane_recommended',
            'kind' => InsightFinding::KIND_SUGGESTION,
            'dedupe_hash' => 's-1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_INFO,
            'title' => 'Consider Octane',
            'body' => 'busy app',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('ignoreFinding', $finding->id);

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_IGNORED, $finding->status);
        $this->assertNotNull($finding->ignored_at);
        $this->assertSame($user->id, $finding->ignored_by_user_id);
    }

    public function test_ignore_action_is_a_noop_for_problem_findings(): void
    {
        [$user, $server] = $this->userWithServer();

        $finding = InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'cpu_ram_usage',
            'kind' => InsightFinding::KIND_PROBLEM,
            'dedupe_hash' => 'p-1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'High CPU',
            'body' => '',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('ignoreFinding', $finding->id);

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_OPEN, $finding->status, 'Problems must not be silenced via ignore — they need to be fixed or auto-resolved');
        $this->assertNull($finding->ignored_at);
    }

    public function test_ignored_suggestion_does_not_reopen_within_cooldown(): void
    {
        [, $server] = $this->userWithServer();

        $this->registerStubInsight('stub_suggestion_cooldown', requires: [], kind: InsightFinding::KIND_SUGGESTION);
        config()->set('insights.insights.stub_suggestion_cooldown.cooldown_days', 30);

        // Initial run creates the suggestion.
        app(InsightRunCoordinator::class)->runForServer($server->fresh());
        $finding = InsightFinding::query()
            ->where('insight_key', 'stub_suggestion_cooldown')
            ->first();
        $this->assertNotNull($finding);

        // User ignores it.
        $finding->forceFill([
            'status' => InsightFinding::STATUS_IGNORED,
            'ignored_at' => now(),
            'ignored_by_user_id' => $server->user_id,
        ])->save();

        // Next scheduled run within cooldown — runner still emits but the recorder must
        // skip the upsert and leave the finding ignored.
        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_IGNORED, $finding->status);
        $this->assertSame(1, InsightFinding::query()->where('insight_key', 'stub_suggestion_cooldown')->count(), 'Cooldown must not create a duplicate row');
    }

    public function test_ignored_suggestion_reopens_after_cooldown_expires(): void
    {
        [, $server] = $this->userWithServer();

        $this->registerStubInsight('stub_suggestion_cooldown_expired', requires: [], kind: InsightFinding::KIND_SUGGESTION);
        config()->set('insights.insights.stub_suggestion_cooldown_expired.cooldown_days', 7);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());
        $finding = InsightFinding::query()
            ->where('insight_key', 'stub_suggestion_cooldown_expired')
            ->first();
        $this->assertNotNull($finding);

        // Ignored 8 days ago — past the 7-day cooldown.
        $finding->forceFill([
            'status' => InsightFinding::STATUS_IGNORED,
            'ignored_at' => now()->subDays(8),
            'ignored_by_user_id' => $server->user_id,
        ])->save();

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_OPEN, $finding->status);
        $this->assertNull($finding->ignored_at, 'Ignore breadcrumbs cleared on reopen so a future ignore restarts the cooldown');
        $this->assertNull($finding->ignored_by_user_id);
    }

    public function test_workspace_insights_renders_suggestions_in_recommendations_section(): void
    {
        [$user, $server] = $this->userWithServer();

        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'octane_recommended',
            'kind' => InsightFinding::KIND_SUGGESTION,
            'dedupe_hash' => 's-1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_INFO,
            'title' => 'Consider enabling Octane',
            'body' => 'Sustained load with idle CPU.',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->assertViewHas('suggestionFindings', fn ($c) => $c->count() === 1)
            ->assertViewHas('findings', fn ($c) => $c->count() === 0)
            ->assertSee('Recommendations')
            ->assertSee('Consider enabling Octane');
    }

    public function test_workspace_banner_excludes_suggestions_even_when_critical(): void
    {
        [$user, $server] = $this->userWithServer();

        // Defensive: a misconfigured suggestion runner with severity=critical must
        // not hijack the banner reserved for actual problems.
        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'misconfigured_suggestion',
            'kind' => InsightFinding::KIND_SUGGESTION,
            'dedupe_hash' => 's-1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_CRITICAL,
            'title' => 'Rogue suggestion',
            'body' => 'Should not page',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->assertViewHas('bannerFindings', fn ($c) => $c->isEmpty());
    }

    public function test_apply_fix_job_dispatches_through_handler_and_resolves_problem_when_recheck_passes(): void
    {
        [$user, $server] = $this->userWithServer();

        // Stub handler that captures invocations.
        $handler = new class implements \App\Services\Insights\Contracts\InsightFixActionInterface
        {
            public bool $preflightCalled = false;

            public bool $applyCalled = false;

            public function preflight($server, $site, $finding, array $params): ?string
            {
                $this->preflightCalled = true;

                return null;
            }

            public function apply($server, $site, $finding, array $params): \App\Services\Insights\FixResult
            {
                $this->applyCalled = true;

                return \App\Services\Insights\FixResult::success('did the thing');
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        $this->registerStubInsightWithHandler('stub_problem_with_fix', $handlerClass);

        // Recorder will run the registered runner and find no candidate → resolves the finding.
        // For this test, the runner emits one finding first, then on recheck the runner returns nothing.
        // Easiest path: the recorder writes a finding; the recheck runs the same stub which returns
        // an empty list when a one-shot flag is flipped. We set up the runner to read a flag.
        config()->set('insights.test.stub_problem_with_fix_should_emit', true);
        $this->setStubRunnerEmits('stub_problem_with_fix', true);

        // Initial run: emit candidate → finding open.
        app(InsightRunCoordinator::class)->runForServer($server->fresh());
        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_problem_with_fix')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->first();
        $this->assertNotNull($finding);

        // After fix: runner returns no candidate so recheck closes the finding.
        $this->setStubRunnerEmits('stub_problem_with_fix', false);

        \App\Jobs\ApplyInsightFixJob::dispatch($finding->id, $user->id);

        $this->assertTrue($handler->preflightCalled);
        $this->assertTrue($handler->applyCalled);

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_RESOLVED, $finding->status);
        $this->assertNotNull($finding->meta['fix_applied_at'] ?? null);
        $this->assertSame($user->id, $finding->meta['fix_applied_by'] ?? null);
    }

    public function test_apply_fix_job_records_failure_when_recheck_still_fails(): void
    {
        [$user, $server] = $this->userWithServer();

        $handler = new class implements \App\Services\Insights\Contracts\InsightFixActionInterface
        {
            public function preflight($server, $site, $finding, array $params): ?string
            {
                return null;
            }

            public function apply($server, $site, $finding, array $params): \App\Services\Insights\FixResult
            {
                return \App\Services\Insights\FixResult::success('shell ran but condition persists');
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        $this->registerStubInsightWithHandler('stub_problem_recheck_fails', $handlerClass);
        $this->setStubRunnerEmits('stub_problem_recheck_fails', true);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());
        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_problem_recheck_fails')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->first();
        $this->assertNotNull($finding);

        // Runner still emits → recheck won't clear the finding.
        \App\Jobs\ApplyInsightFixJob::dispatch($finding->id, $user->id);

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_OPEN, $finding->status);
        $this->assertSame('recheck_still_failing', $finding->meta['fix_failure_reason'] ?? null);
    }

    public function test_apply_fix_job_records_refusal_from_preflight_without_running_apply(): void
    {
        [$user, $server] = $this->userWithServer();

        $handler = new class implements \App\Services\Insights\Contracts\InsightFixActionInterface
        {
            public bool $applyCalled = false;

            public function preflight($server, $site, $finding, array $params): ?string
            {
                return 'not enough RAM headroom';
            }

            public function apply($server, $site, $finding, array $params): \App\Services\Insights\FixResult
            {
                $this->applyCalled = true;

                return \App\Services\Insights\FixResult::success();
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        $this->registerStubInsightWithHandler('stub_problem_refusal', $handlerClass);
        $this->setStubRunnerEmits('stub_problem_refusal', true);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());
        $finding = InsightFinding::query()
            ->where('insight_key', 'stub_problem_refusal')
            ->first();
        $this->assertNotNull($finding);

        \App\Jobs\ApplyInsightFixJob::dispatch($finding->id, $user->id);

        $this->assertFalse($handler->applyCalled);
        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_OPEN, $finding->status);
        $this->assertSame('not enough RAM headroom', $finding->meta['fix_refusal_reason'] ?? null);
    }

    public function test_apply_fix_job_resolves_suggestion_without_recheck(): void
    {
        [$user, $server] = $this->userWithServer();

        $handler = new class implements \App\Services\Insights\Contracts\InsightFixActionInterface
        {
            public function preflight($server, $site, $finding, array $params): ?string
            {
                return null;
            }

            public function apply($server, $site, $finding, array $params): \App\Services\Insights\FixResult
            {
                return \App\Services\Insights\FixResult::success();
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        $this->registerStubInsightWithHandler('stub_suggestion_with_fix', $handlerClass, kind: InsightFinding::KIND_SUGGESTION);
        $this->setStubRunnerEmits('stub_suggestion_with_fix', true);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());
        $finding = InsightFinding::query()
            ->where('insight_key', 'stub_suggestion_with_fix')
            ->first();
        $this->assertNotNull($finding);

        // Even though the runner still emits, the suggestion lifecycle resolves on apply success
        // without rechecking — windowed signals don't clear instantly.
        \App\Jobs\ApplyInsightFixJob::dispatch($finding->id, $user->id);

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_RESOLVED, $finding->status);
    }

    /**
     * Register a synthetic insight with a stub runner whose emit/no-emit state is
     * controlled by setStubRunnerEmits(). Wires the named handler class into config.
     */
    private function registerStubInsightWithHandler(string $key, string $handlerClass, string $kind = InsightFinding::KIND_PROBLEM): void
    {
        $runner = new class($key, $kind, $this) implements InsightRunnerInterface
        {
            public function __construct(
                private string $key,
                private string $kind,
                private \PHPUnit\Framework\TestCase $test,
            ) {}

            public function run(Server $server, ?Site $site, array $parameters): array
            {
                if (! config('insights.test.stub_runner_emits.'.$this->key, true)) {
                    return [];
                }

                return [new InsightCandidate(
                    insightKey: $this->key,
                    dedupeHash: 'stub',
                    severity: 'info',
                    title: 'stub fired',
                    kind: $this->kind,
                )];
            }
        };

        $runnerClass = get_class($runner);
        $this->app->instance($runnerClass, $runner);

        config()->set('insights.insights.'.$key, [
            'label' => 'Stub: '.$key,
            'description' => 'Test stub.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => $runnerClass,
            'fix' => ['handler' => $handlerClass],
            'requires' => [],
            'default_enabled' => true,
            'notify_subscribers' => false,
        ]);
    }

    private function setStubRunnerEmits(string $key, bool $emits): void
    {
        config()->set('insights.test.stub_runner_emits.'.$key, $emits);
    }

    public function test_suggestions_do_not_dispatch_notification_events(): void
    {
        [$owner, $server] = $this->userWithServer();

        $channel = \App\Models\NotificationChannel::factory()->forUser($owner)->create([
            'type' => \App\Models\NotificationChannel::TYPE_SLACK,
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
        ]);
        \App\Models\NotificationSubscription::query()->create([
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => \App\Services\Insights\InsightsNotificationDispatcher::EVENT_KEY,
        ]);

        \Illuminate\Support\Facades\Http::fake();

        $this->registerStubInsight('stub_suggestion_no_notify', requires: [], kind: InsightFinding::KIND_SUGGESTION);

        app(InsightRunCoordinator::class)->runForServer($server->fresh());

        $finding = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', 'stub_suggestion_no_notify')
            ->first();
        $this->assertNotNull($finding);

        $this->assertDatabaseMissing('notification_events', [
            'event_key' => \App\Services\Insights\InsightsNotificationDispatcher::EVENT_KEY,
            'subject_type' => InsightFinding::class,
            'subject_id' => $finding->id,
        ]);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * Register a synthetic insight with an inline stub runner that always emits one candidate.
     * Default-enabled so InsightSettingsRepository::isInsightEnabled() returns true without
     * needing a stored InsightSetting row.
     *
     * @param  list<string>  $requires
     */
    private function registerStubInsight(string $key, array $requires, string $kind = InsightFinding::KIND_PROBLEM): void
    {
        $runner = new class($key, $kind) implements InsightRunnerInterface
        {
            public function __construct(private string $key, private string $kind) {}

            public function run(Server $server, ?Site $site, array $parameters): array
            {
                return [new InsightCandidate(
                    insightKey: $this->key,
                    dedupeHash: 'stub',
                    severity: 'info',
                    title: 'stub fired',
                    kind: $this->kind,
                )];
            }
        };

        $runnerClass = get_class($runner);
        $this->app->instance($runnerClass, $runner);

        config()->set('insights.insights.'.$key, [
            'label' => 'Stub: '.$key,
            'description' => 'Test stub.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => $runnerClass,
            'fix' => null,
            'requires' => $requires,
            'default_enabled' => true,
            'notify_subscribers' => false,
        ]);
    }

    /**
     * @param  list<string>  $expectedServices
     */
    private function seedStackSummary(Server $server, array $expectedServices): void
    {
        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Finished,
            'output' => '',
            'server_id' => $server->id,
            'created_by' => $server->user_id,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);

        $run = ServerProvisionRun::query()->create([
            'server_id' => $server->id,
            'task_id' => $task->id,
            'attempt' => 1,
            'status' => 'completed',
            'summary' => 'Provisioning completed.',
            'started_at' => now()->subMinutes(3),
            'completed_at' => now(),
        ]);

        $run->artifacts()->create([
            'type' => 'stack_summary',
            'key' => 'stack-summary',
            'label' => 'Installed stack',
            'metadata' => [
                'webserver' => in_array('nginx', $expectedServices, true) ? 'nginx' : 'none',
                'database' => in_array('mysql', $expectedServices, true) ? 'mysql' : (in_array('postgresql', $expectedServices, true) ? 'postgresql' : 'none'),
                'cache_service' => 'none',
                'expected_services' => $expectedServices,
            ],
            'content' => '{}',
        ]);
    }
}
