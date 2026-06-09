<?php

namespace Tests\Feature\Insights\WorkspaceInsightsBannerTest;

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
use App\Services\Insights\InsightHealthScoreService;
use App\Services\Insights\InsightRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

beforeEach(function () {
    Feature::define('workspace.insights', fn () => true);
    Feature::flushCache();
});

/**
 * @return array{0: User, 1: Server, 2: Organization}
 */
function userWithServer(): array
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

test('run checks now seeds queued meta and dispatches with run id', function () {
    [$user, $server] = userWithServer();

    Bus::fake();

    Livewire::actingAs($user)
        ->test(ServerWorkspaceInsights::class, ['server' => $server])
        ->call('runChecksNow');

    $server->refresh();
    $meta = $server->meta ?? [];
    expect($meta[config('insights_workspace.meta_run_run_id_key')] ?? null)->not->toBeEmpty('run_id should be seeded');
    expect($meta[config('insights_workspace.meta_run_status_key')] ?? null)->toBe('queued');
    expect($meta[config('insights_workspace.meta_run_started_at_key')] ?? null)->not->toBeEmpty();
    expect($meta)->toHaveKey(config('insights_workspace.meta_run_finished_at_key'));
    expect($meta[config('insights_workspace.meta_run_finished_at_key')])->toBeNull();
    expect($meta)->toHaveKey(config('insights_workspace.meta_run_error_key'));
    expect($meta[config('insights_workspace.meta_run_error_key')])->toBeNull();

    $expectedRunId = (string) $meta[config('insights_workspace.meta_run_run_id_key')];

    Bus::assertDispatched(RunServerInsightsJob::class, function (RunServerInsightsJob $job) use ($server, $expectedRunId) {
        return $job->serverId === $server->id
            && $job->runId === $expectedRunId
            && $job->onlyKey === null;
    });
});

test('run checks now is blocked when a run is already in flight', function () {
    [$user, $server] = userWithServer();

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
});

test('busy gate treats stale runs as unblocked', function () {
    [$user, $server] = userWithServer();

    $server->update(['meta' => [
        config('insights_workspace.meta_run_run_id_key') => 'stale-run',
        config('insights_workspace.meta_run_status_key') => 'running',
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
});

test('apply fix seeds fix banner meta and dispatches with run id', function () {
    [$user, $server] = userWithServer();

    $finding = openFinding($server, null, 'cpu_ram_usage');

    Bus::fake();

    Livewire::actingAs($user)
        ->test(ServerWorkspaceInsights::class, ['server' => $server])
        ->call('applyFix', $finding->id);

    $server->refresh();
    $meta = $server->meta ?? [];
    expect($meta[config('insights_workspace.meta_fix_run_id_key')] ?? null)->not->toBeEmpty();
    expect($meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('queued');
    expect($meta[config('insights_workspace.meta_fix_finding_id_key')] ?? null)->toBe($finding->id);

    $expectedRunId = (string) $meta[config('insights_workspace.meta_fix_run_id_key')];

    Bus::assertDispatched(ApplyInsightFixJob::class, function (ApplyInsightFixJob $job) use ($finding, $user, $expectedRunId) {
        return $job->insightFindingId === $finding->id
            && $job->userId === $user->id
            && $job->runId === $expectedRunId;
    });
});

test('apply fix is blocked when a revert is in flight', function () {
    [$user, $server] = userWithServer();

    $finding = openFinding($server, null, 'cpu_ram_usage');

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
});

test('dismiss insights banner clears the targeted namespace only', function () {
    [$user, $server] = userWithServer();

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
    expect($meta[config('insights_workspace.meta_fix_run_id_key')] ?? null)->toBe('fix-1', 'in-flight fix must survive dismissing run');
    expect($meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('queued');
});

test('dismiss insights banner refuses to clear an in flight run', function () {
    [$user, $server] = userWithServer();

    $server->update(['meta' => [
        config('insights_workspace.meta_run_run_id_key') => 'still-running',
        config('insights_workspace.meta_run_status_key') => 'running',
        config('insights_workspace.meta_run_started_at_key') => now()->toIso8601String(),
    ]]);

    Livewire::actingAs($user)
        ->test(ServerWorkspaceInsights::class, ['server' => $server])
        ->call('dismissInsightsBanner', 'run');

    $server->refresh();
    expect($server->meta[config('insights_workspace.meta_run_run_id_key')] ?? null)->toBe('still-running');
    expect($server->meta[config('insights_workspace.meta_run_status_key')] ?? null)->toBe('running');
});

test('run server insights job streams lifecycle lines to the cache buffer', function () {
    [, $server] = userWithServer();

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
        $this->app->make(InsightHealthScoreService::class),
    );

    $server->refresh();
    $meta = $server->meta ?? [];
    expect($meta[config('insights_workspace.meta_run_status_key')] ?? null)->toBe('completed');
    expect($meta[config('insights_workspace.meta_run_finished_at_key')] ?? null)->not->toBeEmpty();
    expect($meta)->toHaveKey(config('insights_workspace.meta_run_error_key'));
    expect($meta[config('insights_workspace.meta_run_error_key')])->toBeNull();

    $cached = Cache::get(config('insights_workspace.run_output_cache_key_prefix').$runId);
    expect($cached)->toBeArray();
    $lines = $cached['lines'] ?? [];
    $haystack = implode("\n", $lines);
    $this->assertStringContainsString('Starting insights sweep', $haystack);
    $this->assertStringContainsString('[cpu_ram_usage] running', $haystack);
    $this->assertStringContainsString('[cpu_ram_usage] 2 candidate', $haystack);
    $this->assertStringContainsString('[load_average_high] ok (no findings)', $haystack);
    $this->assertStringContainsString('Done — 2 candidate(s) recorded.', $haystack);
});

test('apply insight fix job emits lifecycle lines and marks completed on success', function () {
    [$user, $server] = userWithServer();

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

    $finding = openFinding($server, null, 'banner_test_suggestion', InsightFinding::KIND_SUGGESTION);

    $runId = 'fix-run-abc';

    (new ApplyInsightFixJob($finding->id, $user->id, $runId))->handle(
        $this->app->make(InsightRunCoordinator::class),
    );

    $server->refresh();
    $meta = $server->meta ?? [];
    expect($meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('completed');
    expect($meta[config('insights_workspace.meta_fix_finished_at_key')] ?? null)->not->toBeEmpty();

    $cached = Cache::get(config('insights_workspace.fix_output_cache_key_prefix').$runId);
    expect($cached)->toBeArray();
    $haystack = implode("\n", $cached['lines'] ?? []);
    $this->assertStringContainsString('Applying fix for [banner_test_suggestion]', $haystack);
    $this->assertStringContainsString('Preflighting', $haystack);
    $this->assertStringContainsString('Apply ok.', $haystack);
    $this->assertStringContainsString('line-one', $haystack);
    $this->assertStringContainsString('line-two', $haystack);
    $this->assertStringContainsString('suggestion applied and finding resolved', $haystack);

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_RESOLVED);
});

test('apply insight fix job marks failed when preflight refuses', function () {
    [$user, $server] = userWithServer();

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

    $finding = openFinding($server, null, 'banner_test_refused', InsightFinding::KIND_SUGGESTION);

    $runId = 'fix-run-refused';

    (new ApplyInsightFixJob($finding->id, $user->id, $runId))->handle(
        $this->app->make(InsightRunCoordinator::class),
    );

    $server->refresh();
    expect($server->meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('refused');
    $this->assertStringContainsString('Server not ready', (string) ($server->meta[config('insights_workspace.meta_fix_error_key')] ?? ''));

    $cached = Cache::get(config('insights_workspace.fix_output_cache_key_prefix').$runId);
    $haystack = implode("\n", $cached['lines'] ?? []);
    $this->assertStringContainsString('Refused — Server not ready', $haystack);
});

test('site scoped apply fix writes banner state to site meta not server meta', function () {
    [$user, $server, $org] = userWithServer();

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

    expect($site->meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('completed', 'site.meta should record banner state for site-scoped fix');
    expect($server->meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBeNull('server.meta must NOT carry banner state for a site-scoped fix');

    $cached = Cache::get(config('insights_workspace.fix_output_cache_key_prefix').$runId);
    expect($cached)->toBeArray();
});

test('revert fix seeds revert banner meta and dispatches with run id', function () {
    [$user, $server] = userWithServer();

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
    expect($meta[config('insights_workspace.meta_revert_run_id_key')] ?? null)->not->toBeEmpty();
    expect($meta[config('insights_workspace.meta_revert_status_key')] ?? null)->toBe('queued');
    expect($meta[config('insights_workspace.meta_revert_finding_id_key')] ?? null)->toBe($finding->id);

    $expectedRunId = (string) $meta[config('insights_workspace.meta_revert_run_id_key')];

    Bus::assertDispatched(RevertInsightFixJob::class, function (RevertInsightFixJob $job) use ($finding, $expectedRunId) {
        return $job->insightFindingId === $finding->id && $job->runId === $expectedRunId;
    });
});

test('site workspace run checks seeds site meta and dispatches with run id', function () {
    [$user, $server, $org] = userWithServer();

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
    expect($meta[config('insights_workspace.meta_run_run_id_key')] ?? null)->not->toBeEmpty();
    expect($meta[config('insights_workspace.meta_run_status_key')] ?? null)->toBe('queued');

    $expectedRunId = (string) $meta[config('insights_workspace.meta_run_run_id_key')];

    Bus::assertDispatched(RunSiteInsightsJob::class, function (RunSiteInsightsJob $job) use ($site, $expectedRunId) {
        return $job->siteId === $site->id && $job->runId === $expectedRunId;
    });
});

test('poll reaps stale running banner to failed', function () {
    [$user, $server] = userWithServer();

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
    expect($server->meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('failed');
    expect($server->meta[config('insights_workspace.meta_fix_finished_at_key')] ?? null)->not->toBeEmpty();

    // The auto-reap intentionally leaves error blank — we don't surface
    // queue-worker implementation details to the operator.
    expect($server->meta[config('insights_workspace.meta_fix_error_key')] ?? null)->toBeNull();
});

test('poll does not reap a fresh running banner', function () {
    [$user, $server] = userWithServer();

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
    expect($server->meta[config('insights_workspace.meta_fix_status_key')] ?? null)->toBe('running');
});

test('scheduled dispatch without run id skips banner writes', function () {
    [, $server] = userWithServer();

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
        $this->app->make(InsightHealthScoreService::class),
    );

    $server->refresh();
    expect($server->meta[config('insights_workspace.meta_run_status_key')] ?? null)->toBeNull();
    expect($server->meta[config('insights_workspace.meta_run_run_id_key')] ?? null)->toBeNull();
});

function openFinding(Server $server, ?string $siteId, string $key, string $kind = InsightFinding::KIND_PROBLEM): InsightFinding
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
