<?php

namespace Tests\Feature\Insights;

use App\Jobs\ApplyInsightFixJob;
use App\Jobs\RevertInsightFixJob;
use App\Jobs\RunServerInsightsJob;
use App\Jobs\RunSiteInsightsJob;
use App\Livewire\Servers\WorkspaceInsights as ServerWorkspaceInsights;
use App\Livewire\Sites\WorkspaceInsights as SiteWorkspaceInsights;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\FixResult;
use App\Services\Insights\InsightRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the workspace console banner that streams insights run / apply-fix /
 * revert-fix progress to the server insights and site insights pages. The
 * banner reads run state from server.meta or site.meta (depending on scope)
 * under the `insights_run.*` / `insights_fix.*` / `insights_revert.*` key
 * namespaces, and reads its streaming output buffer from the application
 * cache keyed by run_id.
 */
class WorkspaceInsightsBannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Server, 2: Organization}
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

        return [$user, $server, $org];
    }

    public function test_run_checks_now_seeds_queued_meta_and_dispatches_with_run_id(): void
    {
        [$user, $server] = $this->userWithServer();

        Bus::fake();

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('runChecksNow');

        $server->refresh();
        $meta = $server->meta ?? [];
        $this->assertNotEmpty($meta[config('insights_workspace.meta_run_run_id_key')] ?? null, 'run_id should be seeded');
        $this->assertSame('queued', $meta[config('insights_workspace.meta_run_status_key')] ?? null);
        $this->assertNotEmpty($meta[config('insights_workspace.meta_run_started_at_key')] ?? null);
        $this->assertArrayHasKey(config('insights_workspace.meta_run_finished_at_key'), $meta);
        $this->assertNull($meta[config('insights_workspace.meta_run_finished_at_key')]);
        $this->assertArrayHasKey(config('insights_workspace.meta_run_error_key'), $meta);
        $this->assertNull($meta[config('insights_workspace.meta_run_error_key')]);

        $expectedRunId = (string) $meta[config('insights_workspace.meta_run_run_id_key')];

        Bus::assertDispatched(RunServerInsightsJob::class, function (RunServerInsightsJob $job) use ($server, $expectedRunId) {
            return $job->serverId === $server->id
                && $job->runId === $expectedRunId
                && $job->onlyKey === null;
        });
    }

    public function test_run_checks_now_is_blocked_when_a_run_is_already_in_flight(): void
    {
        [$user, $server] = $this->userWithServer();

        $server->update(['meta' => [
            config('insights_workspace.meta_run_run_id_key') => 'run-already-going',
            config('insights_workspace.meta_run_status_key') => 'running',
            config('insights_workspace.meta_run_started_at_key') => now()->toIso8601String(),
        ]]);

        Bus::fake();

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('runChecksNow');

        Bus::assertNotDispatched(RunServerInsightsJob::class);
    }

    public function test_busy_gate_treats_stale_runs_as_unblocked(): void
    {
        [$user, $server] = $this->userWithServer();

        $server->update(['meta' => [
            config('insights_workspace.meta_run_run_id_key') => 'stale-run',
            config('insights_workspace.meta_run_status_key' ) => 'running',
            // Past the configured stale threshold → stale, should not block re-dispatch.
            config('insights_workspace.meta_run_started_at_key') => now()
                ->subSeconds((int) config('insights_workspace.stale_threshold_seconds') + 60)
                ->toIso8601String(),
        ]]);

        Bus::fake();

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('runChecksNow');

        Bus::assertDispatched(RunServerInsightsJob::class);
    }

    public function test_apply_fix_seeds_fix_banner_meta_and_dispatches_with_run_id(): void
    {
        [$user, $server] = $this->userWithServer();

        $finding = $this->openFinding($server, null, 'cpu_ram_usage');

        Bus::fake();

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('applyFix', $finding->id);

        $server->refresh();
        $meta = $server->meta ?? [];
        $this->assertNotEmpty($meta[config('insights_workspace.meta_fix_run_id_key')] ?? null);
        $this->assertSame('queued', $meta[config('insights_workspace.meta_fix_status_key')] ?? null);
        $this->assertSame($finding->id, $meta[config('insights_workspace.meta_fix_finding_id_key')] ?? null);

        $expectedRunId = (string) $meta[config('insights_workspace.meta_fix_run_id_key')];

        Bus::assertDispatched(ApplyInsightFixJob::class, function (ApplyInsightFixJob $job) use ($finding, $user, $expectedRunId) {
            return $job->insightFindingId === $finding->id
                && $job->userId === $user->id
                && $job->runId === $expectedRunId;
        });
    }

    public function test_apply_fix_is_blocked_when_a_revert_is_in_flight(): void
    {
        [$user, $server] = $this->userWithServer();

        $finding = $this->openFinding($server, null, 'cpu_ram_usage');

        $server->update(['meta' => [
            config('insights_workspace.meta_revert_run_id_key') => 'revert-in-flight',
            config('insights_workspace.meta_revert_status_key') => 'running',
            config('insights_workspace.meta_revert_started_at_key') => now()->toIso8601String(),
        ]]);

        Bus::fake();

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('applyFix', $finding->id);

        Bus::assertNotDispatched(ApplyInsightFixJob::class);
    }

    public function test_dismiss_insights_banner_clears_the_targeted_namespace_only(): void
    {
        [$user, $server] = $this->userWithServer();

        $server->update(['meta' => [
            // Settled run banner — eligible to dismiss.
            config('insights_workspace.meta_run_run_id_key') => 'run-1',
            config('insights_workspace.meta_run_status_key') => 'completed',
            config('insights_workspace.meta_run_finished_at_key') => now()->toIso8601String(),
            // In-flight fix banner — must remain after dismissing run.
            config('insights_workspace.meta_fix_run_id_key') => 'fix-1',
            config('insights_workspace.meta_fix_finding_id_key') => 42,
            config('insights_workspace.meta_fix_status_key') => 'queued',
            config('insights_workspace.meta_fix_started_at_key') => now()->toIso8601String(),
        ]]);

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('dismissInsightsBanner', 'run');

        $server->refresh();
        $meta = $server->meta ?? [];
        $this->assertArrayNotHasKey(config('insights_workspace.meta_run_run_id_key'), $meta);
        $this->assertArrayNotHasKey(config('insights_workspace.meta_run_status_key'), $meta);
        $this->assertSame('fix-1', $meta[config('insights_workspace.meta_fix_run_id_key')] ?? null, 'in-flight fix must survive dismissing run');
        $this->assertSame('queued', $meta[config('insights_workspace.meta_fix_status_key')] ?? null);
    }

    public function test_dismiss_insights_banner_refuses_to_clear_an_in_flight_run(): void
    {
        [$user, $server] = $this->userWithServer();

        $server->update(['meta' => [
            config('insights_workspace.meta_run_run_id_key') => 'still-running',
            config('insights_workspace.meta_run_status_key') => 'running',
            config('insights_workspace.meta_run_started_at_key') => now()->toIso8601String(),
        ]]);

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('dismissInsightsBanner', 'run');

        $server->refresh();
        $this->assertSame('still-running', $server->meta[config('insights_workspace.meta_run_run_id_key')] ?? null);
        $this->assertSame('running', $server->meta[config('insights_workspace.meta_run_status_key')] ?? null);
    }

    public function test_run_server_insights_job_streams_lifecycle_lines_to_the_cache_buffer(): void
    {
        [, $server] = $this->userWithServer();

        // Stub the coordinator so the job doesn't actually try to SSH out.
        $stub = new class extends InsightRunCoordinator
        {
            public function __construct() {}

            public function runForServer($server, $onlyKey = null, $onProgress = null): void
            {
                if ($onProgress !== null) {
                    $onProgress('check.start', 'cpu_ram_usage', []);
                    $onProgress('check.complete', 'cpu_ram_usage', ['candidates' => 2]);
                    $onProgress('check.start', 'load_average_high', []);
                    $onProgress('check.complete', 'load_average_high', ['candidates' => 0]);
                }
            }
        };
        $this->app->instance(InsightRunCoordinator::class, $stub);

        $runId = 'run-test-123';

        (new RunServerInsightsJob($server->id, null, $runId))->handle(
            $stub,
            $this->app->make(\App\Services\Insights\InsightHealthScoreService::class),
        );

        $server->refresh();
        $meta = $server->meta ?? [];
        $this->assertSame('completed', $meta[config('insights_workspace.meta_run_status_key')] ?? null);
        $this->assertNotEmpty($meta[config('insights_workspace.meta_run_finished_at_key')] ?? null);
        $this->assertArrayHasKey(config('insights_workspace.meta_run_error_key'), $meta);
        $this->assertNull($meta[config('insights_workspace.meta_run_error_key')]);

        $cached = Cache::get(config('insights_workspace.run_output_cache_key_prefix').$runId);
        $this->assertIsArray($cached);
        $lines = $cached['lines'] ?? [];
        $haystack = implode("\n", $lines);
        $this->assertStringContainsString('Starting insights sweep', $haystack);
        $this->assertStringContainsString('[cpu_ram_usage] running', $haystack);
        $this->assertStringContainsString('[cpu_ram_usage] 2 candidate', $haystack);
        $this->assertStringContainsString('[load_average_high] ok (no findings)', $haystack);
        $this->assertStringContainsString('Done — 2 candidate(s) recorded.', $haystack);
    }

    public function test_apply_insight_fix_job_emits_lifecycle_lines_and_marks_completed_on_success(): void
    {
        [$user, $server] = $this->userWithServer();

        $handler = new class implements InsightFixActionInterface
        {
            public function preflight($server, $site, $finding, array $params): ?string
            {
                return null;
            }

            public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
            {
                return FixResult::success("line-one\nline-two");
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        config()->set('insights.insights.banner_test_suggestion', [
            'label' => 'Banner test suggestion',
            'description' => '',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => ['handler' => $handlerClass],
            'requires' => [],
            'default_enabled' => true,
        ]);

        $finding = $this->openFinding($server, null, 'banner_test_suggestion', InsightFinding::KIND_SUGGESTION);

        $runId = 'fix-run-abc';

        (new ApplyInsightFixJob($finding->id, $user->id, $runId))->handle(
            $this->app->make(InsightRunCoordinator::class),
        );

        $server->refresh();
        $meta = $server->meta ?? [];
        $this->assertSame('completed', $meta[config('insights_workspace.meta_fix_status_key')] ?? null);
        $this->assertNotEmpty($meta[config('insights_workspace.meta_fix_finished_at_key')] ?? null);

        $cached = Cache::get(config('insights_workspace.fix_output_cache_key_prefix').$runId);
        $this->assertIsArray($cached);
        $haystack = implode("\n", $cached['lines'] ?? []);
        $this->assertStringContainsString('Applying fix for [banner_test_suggestion]', $haystack);
        $this->assertStringContainsString('Preflighting', $haystack);
        $this->assertStringContainsString('Apply ok.', $haystack);
        $this->assertStringContainsString('line-one', $haystack);
        $this->assertStringContainsString('line-two', $haystack);
        $this->assertStringContainsString('suggestion applied and finding resolved', $haystack);

        $finding->refresh();
        $this->assertSame(InsightFinding::STATUS_RESOLVED, $finding->status);
    }

    public function test_apply_insight_fix_job_marks_failed_when_preflight_refuses(): void
    {
        [$user, $server] = $this->userWithServer();

        $handler = new class implements InsightFixActionInterface
        {
            public function preflight($server, $site, $finding, array $params): ?string
            {
                return 'Server not ready (test refusal).';
            }

            public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
            {
                return FixResult::success();
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        config()->set('insights.insights.banner_test_refused', [
            'label' => 'Banner test refused',
            'description' => '',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => ['handler' => $handlerClass],
            'requires' => [],
            'default_enabled' => true,
        ]);

        $finding = $this->openFinding($server, null, 'banner_test_refused', InsightFinding::KIND_SUGGESTION);

        $runId = 'fix-run-refused';

        (new ApplyInsightFixJob($finding->id, $user->id, $runId))->handle(
            $this->app->make(InsightRunCoordinator::class),
        );

        $server->refresh();
        $this->assertSame('refused', $server->meta[config('insights_workspace.meta_fix_status_key')] ?? null);
        $this->assertStringContainsString('Server not ready', (string) ($server->meta[config('insights_workspace.meta_fix_error_key')] ?? ''));

        $cached = Cache::get(config('insights_workspace.fix_output_cache_key_prefix').$runId);
        $haystack = implode("\n", $cached['lines'] ?? []);
        $this->assertStringContainsString('Refused — Server not ready', $haystack);
    }

    public function test_site_scoped_apply_fix_writes_banner_state_to_site_meta_not_server_meta(): void
    {
        [$user, $server, $org] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
        ]);

        $handler = new class implements InsightFixActionInterface
        {
            public function preflight($server, $site, $finding, array $params): ?string
            {
                return null;
            }

            public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
            {
                return FixResult::success('done');
            }
        };
        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        config()->set('insights.insights.banner_test_site_fix', [
            'label' => 'Site banner fix',
            'description' => '',
            'scope' => 'site',
            'requires_pro' => false,
            'runner' => null,
            'fix' => ['handler' => $handlerClass],
            'requires' => [],
            'default_enabled' => true,
        ]);

        $finding = InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'team_id' => null,
            'insight_key' => 'banner_test_site_fix',
            'kind' => InsightFinding::KIND_SUGGESTION,
            'dedupe_hash' => 'site-1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_INFO,
            'title' => 'Site finding',
            'body' => '',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        $runId = 'site-fix-run';

        (new ApplyInsightFixJob($finding->id, $user->id, $runId))->handle(
            $this->app->make(InsightRunCoordinator::class),
        );

        $site->refresh();
        $server->refresh();

        $this->assertSame('completed', $site->meta[config('insights_workspace.meta_fix_status_key')] ?? null, 'site.meta should record banner state for site-scoped fix');
        $this->assertNull($server->meta[config('insights_workspace.meta_fix_status_key')] ?? null, 'server.meta must NOT carry banner state for a site-scoped fix');

        $cached = Cache::get(config('insights_workspace.fix_output_cache_key_prefix').$runId);
        $this->assertIsArray($cached);
    }

    public function test_revert_fix_seeds_revert_banner_meta_and_dispatches_with_run_id(): void
    {
        [$user, $server] = $this->userWithServer();

        $finding = InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'cpu_ram_usage',
            'kind' => InsightFinding::KIND_PROBLEM,
            'dedupe_hash' => 'rev-1',
            'status' => InsightFinding::STATUS_RESOLVED,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'x',
            'body' => '',
            'meta' => ['backup_path' => '/etc/foo.bak'],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => now(),
        ]);

        Bus::fake();

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('revertFix', $finding->id);

        $server->refresh();
        $meta = $server->meta ?? [];
        $this->assertNotEmpty($meta[config('insights_workspace.meta_revert_run_id_key')] ?? null);
        $this->assertSame('queued', $meta[config('insights_workspace.meta_revert_status_key')] ?? null);
        $this->assertSame($finding->id, $meta[config('insights_workspace.meta_revert_finding_id_key')] ?? null);

        $expectedRunId = (string) $meta[config('insights_workspace.meta_revert_run_id_key')];

        Bus::assertDispatched(RevertInsightFixJob::class, function (RevertInsightFixJob $job) use ($finding, $expectedRunId) {
            return $job->insightFindingId === $finding->id && $job->runId === $expectedRunId;
        });
    }

    public function test_site_workspace_run_checks_seeds_site_meta_and_dispatches_with_run_id(): void
    {
        [$user, $server, $org] = $this->userWithServer();

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
        ]);

        Bus::fake();

        Livewire::actingAs($user)
            ->test(SiteWorkspaceInsights::class, ['server' => $server, 'site' => $site])
            ->call('runChecksNow');

        $site->refresh();
        $meta = $site->meta ?? [];
        $this->assertNotEmpty($meta[config('insights_workspace.meta_run_run_id_key')] ?? null);
        $this->assertSame('queued', $meta[config('insights_workspace.meta_run_status_key')] ?? null);

        $expectedRunId = (string) $meta[config('insights_workspace.meta_run_run_id_key')];

        Bus::assertDispatched(RunSiteInsightsJob::class, function (RunSiteInsightsJob $job) use ($site, $expectedRunId) {
            return $job->siteId === $site->id && $job->runId === $expectedRunId;
        });
    }

    public function test_poll_reaps_stale_running_banner_to_failed(): void
    {
        [$user, $server] = $this->userWithServer();

        // Banner left in `running` state past the stale threshold — simulates a worker
        // that died mid-fix without writing terminal meta.
        $server->update(['meta' => [
            config('insights_workspace.meta_fix_run_id_key') => 'fix-stuck',
            config('insights_workspace.meta_fix_finding_id_key') => 99,
            config('insights_workspace.meta_fix_status_key') => 'running',
            config('insights_workspace.meta_fix_started_at_key') => now()
                ->subSeconds((int) config('insights_workspace.stale_threshold_seconds') + 60)
                ->toIso8601String(),
        ]]);

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('pollInsightsStatus');

        $server->refresh();
        $this->assertSame('failed', $server->meta[config('insights_workspace.meta_fix_status_key')] ?? null);
        $this->assertNotEmpty($server->meta[config('insights_workspace.meta_fix_finished_at_key')] ?? null);
        // The auto-reap intentionally leaves error blank — we don't surface
        // queue-worker implementation details to the operator.
        $this->assertNull($server->meta[config('insights_workspace.meta_fix_error_key')] ?? null);
    }

    public function test_poll_does_not_reap_a_fresh_running_banner(): void
    {
        [$user, $server] = $this->userWithServer();

        $server->update(['meta' => [
            config('insights_workspace.meta_fix_run_id_key') => 'fix-fresh',
            config('insights_workspace.meta_fix_finding_id_key') => 17,
            config('insights_workspace.meta_fix_status_key') => 'running',
            config('insights_workspace.meta_fix_started_at_key') => now()->subSeconds(30)->toIso8601String(),
        ]]);

        Livewire::actingAs($user)
            ->test(ServerWorkspaceInsights::class, ['server' => $server])
            ->call('pollInsightsStatus');

        $server->refresh();
        $this->assertSame('running', $server->meta[config('insights_workspace.meta_fix_status_key')] ?? null);
    }

    public function test_scheduled_dispatch_without_run_id_skips_banner_writes(): void
    {
        [, $server] = $this->userWithServer();

        // Stub coordinator so the job doesn't reach for SSH.
        $stub = new class extends InsightRunCoordinator
        {
            public function __construct() {}

            public function runForServer($server, $onlyKey = null, $onProgress = null): void {}

            public function runForSite($site, $onlyKey = null, $onProgress = null): void {}
        };
        $this->app->instance(InsightRunCoordinator::class, $stub);

        // No runId — scheduler / setup-script / post-deploy callers stay silent.
        (new RunServerInsightsJob($server->id))->handle(
            $stub,
            $this->app->make(\App\Services\Insights\InsightHealthScoreService::class),
        );

        $server->refresh();
        $this->assertNull($server->meta[config('insights_workspace.meta_run_status_key')] ?? null);
        $this->assertNull($server->meta[config('insights_workspace.meta_run_run_id_key')] ?? null);
    }

    private function openFinding(Server $server, ?string $siteId, string $key, string $kind = InsightFinding::KIND_PROBLEM): InsightFinding
    {
        return InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => $siteId,
            'team_id' => null,
            'insight_key' => $key,
            'kind' => $kind,
            'dedupe_hash' => $key.'-'.uniqid(),
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => $kind === InsightFinding::KIND_SUGGESTION
                ? InsightFinding::SEVERITY_INFO
                : InsightFinding::SEVERITY_WARNING,
            'title' => 'Test finding',
            'body' => '',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);
    }
}
