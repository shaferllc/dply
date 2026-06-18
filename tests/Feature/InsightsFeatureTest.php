<?php

namespace Tests\Feature\InsightsFeatureTest;

use App\Modules\Insights\Jobs\ApplyInsightFixJob;
use App\Modules\Insights\Jobs\RevertInsightFixJob;
use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Livewire\Servers\WorkspaceInsights;
use App\Livewire\Settings\Hub;
use App\Models\InsightFinding;
use App\Models\InsightSetting;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\ServerProvisionRun;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\Insights\Services\Contracts\InsightFixActionInterface;
use App\Modules\Insights\Services\Contracts\InsightRunnerInterface;
use App\Modules\Insights\Services\FixActions\BumpFpmWorkersFixAction;
use App\Modules\Insights\Services\FixActions\EnableNtpFixAction;
use App\Modules\Insights\Services\FixResult;
use App\Modules\Insights\Services\InsightCandidate;
use App\Modules\Insights\Services\InsightRunCoordinator;
use App\Modules\Insights\Services\InsightSettingsRepository;
use App\Modules\Insights\Services\InsightsNotificationDispatcher;
use App\Modules\Insights\Services\Runners\HorizonRecommendedInsightRunner;
use App\Modules\Insights\Services\Runners\OctaneRecommendedInsightRunner;
use App\Modules\Insights\Services\Runners\PackageSecurityUpdatesInsightRunner;
use App\Modules\Insights\Services\Runners\PhpFpmWorkersUndersizedInsightRunner;
use App\Modules\Insights\Services\Runners\SystemClockSyncInsightRunner;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpFpmProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.insights');

beforeEach(function (): void {
    // FakesRemoteServerAccess fakes the queue globally; ShouldQueue jobs
    // dispatched here must run inline for assertions on side effects.
    Queue::getFacadeRoot()->except([
        RunServerInsightsJob::class,
        ApplyInsightFixJob::class,
        RevertInsightFixJob::class,
    ]);
});

/**
 * @return array{0: User, 1: Server}
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

    return [$user, $server];
}

test('server insights page renders for owner', function () {
    [$user, $server] = userWithServer();

    $this->actingAs($user)
        ->get(route('servers.insights', $server))
        ->assertOk();
});

test('server insights page shows only open findings', function () {
    [$user, $server] = userWithServer();

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
});

test('server overview shows open insights summary', function () {
    [$user, $server] = userWithServer();

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
});

test('save settings persists enabled map', function () {
    [$user, $server] = userWithServer();

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
    expect($row)->not->toBeNull();
    expect((bool) ($row->enabled_map['cpu_ram_usage'] ?? true))->toBeFalse();
});

test('heartbeat insight creates info finding when enabled', function () {
    [$user, $server] = userWithServer();
    $org = $server->organization;
    expect($org)->not->toBeNull();
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
});

test('run server insights job records cpu finding when high', function () {
    [$user, $server] = userWithServer();

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
});

test('run server insights job records missing metrics finding when no snapshots exist', function () {
    [$user, $server] = userWithServer();

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
});

test('run server insights job skips missing metrics finding when monitoring not installed', function () {
    [$user, $server] = userWithServer();

    Bus::dispatchSync(new RunServerInsightsJob($server->id));

    $this->assertDatabaseMissing('insight_findings', [
        'server_id' => $server->id,
        'insight_key' => 'metrics_missing_or_stale',
        'status' => InsightFinding::STATUS_OPEN,
    ]);
});

test('run server insights job records health check url missing finding for hosting server', function () {
    [$user, $server] = userWithServer();

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
});

test('site insights page renders', function () {
    [$user, $server] = userWithServer();

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
});

test('acknowledge finding clears banner but keeps finding in list', function () {
    [$user, $server] = userWithServer();

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
    expect($crit->acknowledged_at)->not->toBeNull();
    expect($crit->acknowledged_by_user_id)->toBe($user->id);

    // After ack: out of the active list + banner, into the dedicated
    // dismissed section (its own tab) so it stays visible without burying
    // the actively-firing findings.
    $component->assertViewHas('bannerFindings', fn ($c) => $c->isEmpty())
        ->assertViewHas('findings', fn ($c) => $c->where('id', $crit->id)->isEmpty())
        ->assertViewHas('dismissedFindings', fn ($c) => $c->where('id', $crit->id)->count() === 1);
});

test('findings are ordered by severity then recency', function () {
    [$user, $server] = userWithServer();

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
});

test('reopened finding clears prior acknowledgement', function () {
    [$user, $server] = userWithServer();

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
    expect($finding)->not->toBeNull();

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
    expect($finding->status)->toBe(InsightFinding::STATUS_RESOLVED);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now()->addMinutes(2),
        'payload' => ['cpu_pct' => 95, 'mem_pct' => 10, 'disk_pct' => 10, 'load_1m' => 0.5],
    ]);
    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->acknowledged_at)->toBeNull();
    expect($finding->acknowledged_by_user_id)->toBeNull();
});

test('insight run coordinator resolves when condition clears', function () {
    [$user, $server] = userWithServer();

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
    expect($open)->not->toBeNull();

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
    expect($openAfter)->toBeNull();

    $resolved = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'cpu_ram_usage')
        ->where('status', InsightFinding::STATUS_RESOLVED)
        ->first();
    expect($resolved)->not->toBeNull();
});

test('coordinator skips runner when required stack tag is absent', function () {
    [, $server] = userWithServer();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    // no mysql
    registerStubInsight('stub_requires_mysql', requires: ['mysql']);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    expect(InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_requires_mysql')
        ->count())->toBe(0, 'Runner with requires=[mysql] must not execute on a Postgres-only stack');
});

test('coordinator runs runner when required stack tag is present', function () {
    [, $server] = userWithServer();
    seedStackSummary($server, ['nginx', 'mysql']);

    registerStubInsight('stub_requires_mysql', requires: ['mysql']);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    expect(InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_requires_mysql')
        ->where('status', InsightFinding::STATUS_OPEN)
        ->count())->toBe(1);
});

test('coordinator fails open when stack summary is unknown', function () {
    [, $server] = userWithServer();

    // No stack-summary artifact seeded → tagsFor() returns 'unknown'.
    registerStubInsight('stub_requires_mysql', requires: ['mysql']);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    expect(InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_requires_mysql')
        ->where('status', InsightFinding::STATUS_OPEN)
        ->count())->toBe(1, 'Fresh server with no provision artifact must fail open and run gated runners');
});

test('recorder persists kind from candidate', function () {
    [, $server] = userWithServer();

    registerStubInsight('stub_suggestion', requires: [], kind: InsightFinding::KIND_SUGGESTION);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    $finding = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_suggestion')
        ->first();
    expect($finding)->not->toBeNull();
    expect($finding->kind)->toBe(InsightFinding::KIND_SUGGESTION);
});

test('recorder defaults kind to problem when unspecified', function () {
    [, $server] = userWithServer();

    registerStubInsight('stub_problem_default', requires: []);

    // kind default
    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    $finding = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_problem_default')
        ->first();
    expect($finding)->not->toBeNull();
    expect($finding->kind)->toBe(InsightFinding::KIND_PROBLEM);
});

test('package security updates runner emits warning when security updates present', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("total=12\nsecurity=3\n");

    $runner = app(PackageSecurityUpdatesInsightRunner::class);
    $candidates = $runner->run($server->fresh(), null, []);

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->insightKey)->toBe('package_security_updates');
    expect($c->kind)->toBe(InsightFinding::KIND_PROBLEM);
    expect($c->severity)->toBe(InsightFinding::SEVERITY_WARNING);
    expect($c->meta['signal']['security_count'])->toBe(3);
    expect($c->meta['signal']['total_upgradable'])->toBe(12);
    $this->assertStringContainsString('3 security updates', $c->title);
});

test('package security updates runner escalates to critical above ten', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("total=42\nsecurity=15\n");

    $runner = app(PackageSecurityUpdatesInsightRunner::class);
    $candidates = $runner->run($server->fresh(), null, []);

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->severity)->toBe(InsightFinding::SEVERITY_CRITICAL);
});

test('package security updates runner skips when no security updates', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("total=2\nsecurity=0\n");

    $runner = app(PackageSecurityUpdatesInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

test('package security updates runner skips when apt not present', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("no-apt\n");

    $runner = app(PackageSecurityUpdatesInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

test('package security updates runner respects min threshold', function () {
    [, $server] = userWithServer();

    // Threshold 5; only 2 security updates → below threshold, no emission.
    stubRemoteBashOutput("total=10\nsecurity=2\n");

    $runner = app(PackageSecurityUpdatesInsightRunner::class);
    expect($runner->run($server->fresh(), null, ['min_security_updates' => 5]))->toBe([]);
});

test('system clock sync runner emits warning when ntp inactive', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("ntp_service=inactive\nsynchronized=yes\ntimezone=UTC\n");

    $runner = app(SystemClockSyncInsightRunner::class);
    $candidates = $runner->run($server->fresh(), null, []);

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->insightKey)->toBe('system_clock_sync');
    expect($c->kind)->toBe(InsightFinding::KIND_PROBLEM);
    expect($c->severity)->toBe(InsightFinding::SEVERITY_WARNING);
    expect($c->meta['signal']['ntp_service'])->toBe('inactive');
    $this->assertStringContainsString('NTP service is not active', $c->body);
});

test('system clock sync runner emits when synchronized is no', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("ntp_service=active\nsynchronized=no\ntimezone=UTC\n");

    $runner = app(SystemClockSyncInsightRunner::class);
    $candidates = $runner->run($server->fresh(), null, []);

    expect($candidates)->toHaveCount(1);
    $this->assertStringContainsString('not reported as synchronized', $candidates[0]->body);
});

test('system clock sync runner skips when clock is synchronized', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("ntp_service=active\nsynchronized=yes\ntimezone=UTC\n");

    $runner = app(SystemClockSyncInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

test('system clock sync runner skips when timedatectl not present', function () {
    [, $server] = userWithServer();

    stubRemoteBashOutput("no-timedatectl\n");

    $runner = app(SystemClockSyncInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

test('enable ntp fix action runs timedatectl set ntp true', function () {
    [, $server] = userWithServer();

    $captured = ['name' => null, 'script' => null, 'as_root' => null];
    stubRemoteBashCapturing($captured, "NTP service: active\nSystem clock synchronized: yes\n");

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'system_clock_sync',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'clock',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_WARNING,
        'title' => 'x',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    $action = app(EnableNtpFixAction::class);
    expect($action->preflight($server->fresh(), null, $finding, []))->toBeNull();
    $result = $action->apply($server->fresh(), null, $finding, []);

    expect($result->ok)->toBeTrue();
    expect($captured['name'])->toBe('insight-fix-enable-ntp');
    $this->assertStringContainsString('timedatectl set-ntp true', (string) $captured['script']);
    expect($captured['as_root'])->toBeTrue();
});

/**
 * Bind a stub ExecuteRemoteTaskOnServer that returns the given buffer string.
 */
function stubRemoteBashOutput(string $buffer): void
{
    $stub = new class($buffer) extends ExecuteRemoteTaskOnServer
    {
        public function __construct(private string $buffer) {}

        public function runInlineBash(Server $server, string $name, string $inlineBash, ?int $timeoutSeconds = null, bool $asRoot = false): ProcessOutput
        {
            return new ProcessOutput($this->buffer, 0, false);
        }
    };
    app()->instance(ExecuteRemoteTaskOnServer::class, $stub);
}

/**
 * Bind a stub that captures invocation args into the given array reference.
 */
function stubRemoteBashCapturing(array &$captured, string $buffer): void
{
    $stub = new class($captured, $buffer) extends ExecuteRemoteTaskOnServer
    {
        public function __construct(array &$captured, private string $buffer)
        {
            $this->captured = &$captured;
        }

        public function runInlineBash(Server $server, string $name, string $inlineBash, ?int $timeoutSeconds = null, bool $asRoot = false): ProcessOutput
        {
            $this->captured['name'] = $name;
            $this->captured['script'] = $inlineBash;
            $this->captured['as_root'] = $asRoot;

            return new ProcessOutput($this->buffer, 0, false);
        }
    };
    app()->instance(ExecuteRemoteTaskOnServer::class, $stub);
}
test('php fpm workers undersized runner emits when active over threshold', function () {
    [, $server] = userWithServer();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    stubFpmProbe(['max_children' => 30, 'active_workers' => 28, 'php_version' => '8.3']);

    $runner = app(PhpFpmWorkersUndersizedInsightRunner::class);
    $candidates = $runner->run($server->fresh(), null, []);

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->insightKey)->toBe('php_fpm_workers_undersized');
    expect($c->kind)->toBe(InsightFinding::KIND_SUGGESTION);
    expect($c->meta['signal']['active_workers'])->toBe(28);
    expect($c->meta['signal']['max_children'])->toBe(30);
    expect($c->meta['signal']['php_version'])->toBe('8.3');
});

test('php fpm workers undersized runner skips below threshold', function () {
    [, $server] = userWithServer();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    stubFpmProbe(['max_children' => 30, 'active_workers' => 5, 'php_version' => '8.3']);

    $runner = app(PhpFpmWorkersUndersizedInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

test('php fpm workers undersized runner skips when probe fails', function () {
    [, $server] = userWithServer();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    stubFpmProbe(null);

    $runner = app(PhpFpmWorkersUndersizedInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

test('bump fpm workers fix action backs up substitutes and writes via editor', function () {
    [, $server] = userWithServer();
    $server->forceFill(['ssh_private_key' => 'fake-key'])->save();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    // Snapshot with mem_total_kb so resolveTotalRamMb() succeeds.
    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['mem_total_kb' => 4 * 1024 * 1024], // 4 GB
    ]);

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'php_fpm_workers_undersized',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'pool:www',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'PHP-FPM nearing worker ceiling',
        'body' => '',
        'meta' => [
            'signal' => [
                'php_version' => '8.3',
                'max_children' => 30,
                'active_workers' => 28,
                'ratio' => 0.93,
                'threshold' => 0.85,
            ],
        ],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    // Stub remote: capture the backup script invocation; return ProcessOutput with success.
    $remoteCalls = [];
    $stubRemote = new class($remoteCalls) extends ExecuteRemoteTaskOnServer
    {
        public function __construct(array &$calls)
        {
            $this->calls = &$calls;
        }

        public function runInlineBash(Server $server, string $name, string $inlineBash, ?int $timeoutSeconds = null, bool $asRoot = false): ProcessOutput
        {
            $this->calls[] = ['name' => $name, 'script' => $inlineBash];

            return new ProcessOutput('backed-up', 0, false);
        }
    };
    $this->app->instance(ExecuteRemoteTaskOnServer::class, $stubRemote);

    // Stub editor: capture saveTarget() args + return content from openTarget().
    $editorState = ['saved_content' => null, 'saved_target' => null, 'saved_version' => null];
    $stubEditor = new class($editorState) extends ServerPhpConfigEditor
    {
        public function __construct(array &$state)
        {
            $this->state = &$state;
        }

        public function openTarget(Server $server, string $version, string $target): array
        {
            return [
                'version' => $version,
                'target' => $target,
                'label' => 'Pool config',
                'path' => "/etc/php/{$version}/fpm/pool.d/www.conf",
                'content' => "[www]\npm = dynamic\npm.max_children = 30\npm.start_servers = 5\n",
                'reload_guidance' => '',
            ];
        }

        public function saveTarget(Server $server, string $version, string $target, string $content, ?User $user = null, ?string $summary = null): array
        {
            $this->state['saved_content'] = $content;
            $this->state['saved_target'] = $target;
            $this->state['saved_version'] = $version;

            return ['message' => 'saved', 'reload_guidance' => '', 'verification_output' => null, 'output' => 'php-fpm test ok'];
        }
    };
    $this->app->instance(ServerPhpConfigEditor::class, $stubEditor);

    // 4GB * 0.6 / 30 = ~81 → proposed should be 81 (or capped). Current 30 → 81 increase passes preflight.
    $action = app(BumpFpmWorkersFixAction::class);
    $params = (array) (config('insights.insights.php_fpm_workers_undersized.fix.params') ?? []);
    expect($action->preflight($server->fresh(), null, $finding, $params))->toBeNull();
    $result = $action->apply($server->fresh(), null, $finding, $params);

    expect($result->ok)->toBeTrue('Apply should succeed: '.$result->errorMessage);

    // Backup script ran first.
    expect($stubRemote->calls)->not->toBeEmpty();
    expect($stubRemote->calls[0]['name'])->toBe('insight-fix-fpm-backup');
    $this->assertStringContainsString('/etc/php/8.3/fpm/pool.d/www.conf', $stubRemote->calls[0]['script']);

    // Editor save happened with substituted content.
    expect($editorState['saved_target'])->toBe('pool_config');
    expect($editorState['saved_version'])->toBe('8.3');
    $this->assertStringContainsString('pm.max_children = 81', (string) $editorState['saved_content']);
    $this->assertStringNotContainsString('pm.max_children = 30', (string) $editorState['saved_content']);

    // Backup path stamped on finding.
    $finding->refresh();
    expect($finding->meta['backup_path'] ?? null)->not->toBeEmpty();
    $this->assertStringContainsString('.dply-backup-', $finding->meta['backup_path']);
    expect($finding->meta['fix_change']['pm_max_children_before'])->toBe(30);
    expect($finding->meta['fix_change']['pm_max_children_after'])->toBe(81);
});
test('bump fpm workers fix action preflight refuses without total ram', function () {
    [, $server] = userWithServer();
    $server->forceFill(['ssh_private_key' => 'fake-key'])->save();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    // No metric snapshot → no mem_total_kb → resolveTotalRamMb returns null.
    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'php_fpm_workers_undersized',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'pool:www',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'x',
        'body' => '',
        'meta' => ['signal' => ['php_version' => '8.3', 'max_children' => 30, 'active_workers' => 28]],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    $action = app(BumpFpmWorkersFixAction::class);
    $reason = $action->preflight($server->fresh(), null, $finding, []);
    expect($reason)->not->toBeNull();
    $this->assertStringContainsString('RAM', $reason);
});

test('bump fpm workers revert restores backup via editor and clears backup path', function () {
    [, $server] = userWithServer();
    $server->forceFill(['ssh_private_key' => 'fake-key'])->save();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'php_fpm_workers_undersized',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'pool:www',
        'status' => InsightFinding::STATUS_RESOLVED,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'PHP-FPM nearing worker ceiling',
        'body' => '',
        'meta' => [
            'signal' => ['php_version' => '8.3', 'max_children' => 30, 'active_workers' => 28],
            'backup_path' => '/etc/php/8.3/fpm/pool.d/www.conf.dply-backup-20260504000000',
            'fix_change' => ['pm_max_children_before' => 30, 'pm_max_children_after' => 81],
        ],
        'correlation' => null,
        'detected_at' => now()->subHour(),
        'resolved_at' => now(),
    ]);

    $stubRemote = new class extends ExecuteRemoteTaskOnServer
    {
        public function __construct() {}

        public function runInlineBash(Server $server, string $name, string $inlineBash, ?int $timeoutSeconds = null, bool $asRoot = false): ProcessOutput
        {
            // Pretend we read the backup successfully — return prior config content.
            return new ProcessOutput("[www]\npm = dynamic\npm.max_children = 30\n", 0, false);
        }
    };
    $this->app->instance(ExecuteRemoteTaskOnServer::class, $stubRemote);

    $editorState = ['saved_content' => null];
    $stubEditor = new class($editorState) extends ServerPhpConfigEditor
    {
        public function __construct(array &$state)
        {
            $this->state = &$state;
        }

        public function saveTarget(Server $server, string $version, string $target, string $content, ?User $user = null, ?string $summary = null): array
        {
            $this->state['saved_content'] = $content;

            return ['message' => 'reverted', 'reload_guidance' => '', 'verification_output' => null, 'output' => 'php-fpm test ok'];
        }
    };
    $this->app->instance(ServerPhpConfigEditor::class, $stubEditor);

    $action = app(BumpFpmWorkersFixAction::class);
    $result = $action->revert($server->fresh(), null, $finding, []);

    expect($result->ok)->toBeTrue('Revert should succeed: '.$result->errorMessage);
    $this->assertStringContainsString('pm.max_children = 30', (string) $editorState['saved_content']);

    $finding->refresh();
    $this->assertArrayNotHasKey('backup_path', $finding->meta ?? [], 'backup_path should be cleared on revert');
    expect($finding->meta['revert_applied_at'] ?? null)->not->toBeNull();
});
test('revert insight fix job refuses when handler is not revertable', function () {
    [$user, $server] = userWithServer();

    // Synthetic insight whose handler implements only the apply interface (no Revertable).
    $handler = new class implements InsightFixActionInterface
    {
        public function preflight($server, $site, $finding, array $params): ?string
        {
            return null;
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            return FixResult::success();
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    config()->set('insights.insights.stub_apply_only_fix', [
        'label' => 'Stub apply-only',
        'description' => '',
        'scope' => 'server',
        'requires_pro' => false,
        'runner' => null,
        'fix' => ['handler' => $handlerClass],
        'requires' => [],
        'default_enabled' => true,
    ]);

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'stub_apply_only_fix',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'r-1',
        'status' => InsightFinding::STATUS_RESOLVED,
        'severity' => InsightFinding::SEVERITY_WARNING,
        'title' => 'x',
        'body' => '',
        'meta' => ['backup_path' => '/etc/somewhere.conf.bak'],
        'correlation' => null,
        'detected_at' => now()->subHour(),
        'resolved_at' => now(),
    ]);

    RevertInsightFixJob::dispatch($finding->id, $user->id);

    $finding->refresh();
    expect($finding->meta['revert_failure_reason'] ?? null)->toBe('handler_not_revertable');
});

test('revert fix action in workspace dispatches job and panel renders recently applied', function () {
    [$user, $server] = userWithServer();

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'php_fpm_workers_undersized',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'pool:www',
        'status' => InsightFinding::STATUS_RESOLVED,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'PHP-FPM nearing worker ceiling',
        'body' => '',
        'meta' => [
            'signal' => ['php_version' => '8.3', 'max_children' => 30, 'active_workers' => 28],
            'backup_path' => '/etc/php/8.3/fpm/pool.d/www.conf.dply-backup-X',
            'fix_change' => ['pm_max_children_before' => 30, 'pm_max_children_after' => 81],
        ],
        'correlation' => null,
        'detected_at' => now()->subHour(),
        'resolved_at' => now(),
    ]);

    Bus::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->assertViewHas('recentlyAppliedFindings', fn ($c) => $c->count() === 1)
        ->assertSee('Recently applied fixes')
        ->assertSee('Revert')
        ->call('revertFix', $finding->id);

    Bus::assertDispatched(RevertInsightFixJob::class, function ($job) use ($finding) {
        return $job->insightFindingId === $finding->id;
    });
});

test('recently applied panel excludes findings without backup path', function () {
    [$user, $server] = userWithServer();

    // Resolved without backup_path → not in the panel.
    InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'cpu_ram_usage',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'r',
        'status' => InsightFinding::STATUS_RESOLVED,
        'severity' => InsightFinding::SEVERITY_WARNING,
        'title' => 'High CPU',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now()->subHour(),
        'resolved_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->assertViewHas('recentlyAppliedFindings', fn ($c) => $c->isEmpty());
});

test('apply fix job refuses config mutating fix when org disables it', function () {
    [$user, $server] = userWithServer();

    // Disable config mutation at the org level.
    $org = $server->organization;
    $org->forceFill([
        'insights_preferences' => array_merge(
            is_array($org->insights_preferences) ? $org->insights_preferences : [],
            ['allow_config_mutation' => false]
        ),
    ])->save();

    $handler = new class implements InsightFixActionInterface
    {
        public bool $applyCalled = false;

        public function preflight($server, $site, $finding, array $params): ?string
        {
            return null;
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            $this->applyCalled = true;

            return FixResult::success();
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    config()->set('insights.insights.stub_mutating_fix', [
        'label' => 'Stub mutating',
        'description' => '',
        'scope' => 'server',
        'requires_pro' => false,
        'runner' => null,
        'fix' => ['handler' => $handlerClass, 'mutates_config' => true],
        'requires' => [],
        'default_enabled' => true,
    ]);

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'stub_mutating_fix',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'm-1',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_WARNING,
        'title' => 'x',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    ApplyInsightFixJob::dispatch($finding->id, $user->id);

    expect($handler->applyCalled)->toBeFalse('Handler must not run when org disables config mutation');
    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->meta['fix_refusal_reason'] ?? null)->toBe('config_mutation_disabled_by_org');
});
test('org can toggle allow config mutation through settings hub', function () {
    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $owner->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($owner)
        ->test(Hub::class)
        ->set('organizationInsights.allow_config_mutation', false)
        ->call('saveOrganizationInsights');

    $org->refresh();
    expect($org->insights_preferences['allow_config_mutation'] ?? null)->toBe(false);
});

test('apply fix job runs config mutating fix when org allows it', function () {
    [$user, $server] = userWithServer();

    // Default behavior: no `allow_config_mutation` key set → gate is open.
    $handler = new class implements InsightFixActionInterface
    {
        public bool $applyCalled = false;

        public function preflight($server, $site, $finding, array $params): ?string
        {
            return null;
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            $this->applyCalled = true;

            return FixResult::success();
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    config()->set('insights.insights.stub_mutating_fix_default', [
        'label' => 'Stub mutating default',
        'description' => '',
        'scope' => 'server',
        'requires_pro' => false,
        'runner' => null,
        'fix' => ['handler' => $handlerClass, 'mutates_config' => true],
        'requires' => [],
        'default_enabled' => true,
    ]);

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'stub_mutating_fix_default',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'm-2',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'x',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    ApplyInsightFixJob::dispatch($finding->id, $user->id);

    expect($handler->applyCalled)->toBeTrue();
});
test('bump fpm workers fix action aborts when pattern not found', function () {
    [, $server] = userWithServer();
    $server->forceFill(['ssh_private_key' => 'fake-key'])->save();
    seedStackSummary($server, ['nginx', 'php-fpm']);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['mem_total_kb' => 4 * 1024 * 1024],
    ]);

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'php_fpm_workers_undersized',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'pool:www',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'x',
        'body' => '',
        'meta' => ['signal' => ['php_version' => '8.3', 'max_children' => 30, 'active_workers' => 28]],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    $stubRemote = new class extends ExecuteRemoteTaskOnServer
    {
        public function __construct() {}

        public function runInlineBash(Server $server, string $name, string $inlineBash, ?int $timeoutSeconds = null, bool $asRoot = false): ProcessOutput
        {
            return new ProcessOutput('backed-up', 0, false);
        }
    };
    $this->app->instance(ExecuteRemoteTaskOnServer::class, $stubRemote);

    // Editor returns content with no pm.max_children line — substitution is a no-op.
    $stubEditor = new class extends ServerPhpConfigEditor
    {
        public function __construct() {}

        public function openTarget(Server $server, string $version, string $target): array
        {
            return [
                'version' => $version,
                'target' => $target,
                'label' => 'Pool config',
                'path' => "/etc/php/{$version}/fpm/pool.d/www.conf",
                'content' => "[www]\npm = dynamic\n; no max_children line at all\n",
                'reload_guidance' => '',
            ];
        }

        public function saveTarget(Server $server, string $version, string $target, string $content, ?User $user = null, ?string $summary = null): array
        {
            throw new \RuntimeException('saveTarget should not be called when substitution is a no-op');
        }
    };
    $this->app->instance(ServerPhpConfigEditor::class, $stubEditor);

    $action = app(BumpFpmWorkersFixAction::class);
    $result = $action->apply($server->fresh(), null, $finding, []);

    expect($result->ok)->toBeFalse();
    expect($result->errorMessage)->not->toBeNull();
    $this->assertStringContainsString('pm.max_children', $result->errorMessage);
});

test('php fpm workers undersized runner skips when no php version', function () {
    [, $server] = userWithServer();

    // No stack-summary seeded → phpVersionFor() returns null → runner returns [].
    $runner = app(PhpFpmWorkersUndersizedInsightRunner::class);
    expect($runner->run($server->fresh(), null, []))->toBe([]);
});

/**
 * Bind a stub ServerPhpFpmProbe that returns the given snapshot.
 *
 * @param  array{max_children: int, active_workers: int, php_version: string}|null  $snapshot
 */
function stubFpmProbe(?array $snapshot): void
{
    $stub = new class($snapshot) extends ServerPhpFpmProbe
    {
        public function __construct(private ?array $snapshot)
        {
            // Skip parent constructor — we don't need the SSH executor in tests.
        }

        public function probe(Server $server, string $phpVersion): ?array
        {
            return $this->snapshot;
        }
    };
    app()->instance(ServerPhpFpmProbe::class, $stub);
}

test('horizon recommended runner emits for laravel site with queue worker and no horizon', function () {
    [, $server] = userWithServer();

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

    SupervisorProgram::query()->create([
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

    $runner = app(HorizonRecommendedInsightRunner::class);
    $candidates = $runner->run($server, $site, []);

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->insightKey)->toBe('horizon_recommended');
    expect($c->kind)->toBe(InsightFinding::KIND_SUGGESTION);
    expect($c->meta['signal']['has_supervisor_queue_worker'])->toBeTrue();
});

test('horizon recommended runner skips when already on horizon', function () {
    [, $server] = userWithServer();

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

    SupervisorProgram::query()->create([
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

    $runner = app(HorizonRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('horizon recommended runner skips when no queue worker present', function () {
    [, $server] = userWithServer();

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
    $runner = app(HorizonRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('horizon recommended runner skips inactive queue worker', function () {
    [, $server] = userWithServer();

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

    SupervisorProgram::query()->create([
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

    $runner = app(HorizonRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('octane recommended runner emits suggestion for busy laravel site without octane', function () {
    [, $server] = userWithServer();

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

    $runner = app(OctaneRecommendedInsightRunner::class);
    $candidates = $runner->run($server, $site, []);

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->insightKey)->toBe('octane_recommended');
    expect($c->kind)->toBe(InsightFinding::KIND_SUGGESTION);
    expect($c->severity)->toBe(InsightFinding::SEVERITY_INFO);
    expect($c->dedupeHash)->toBe('site:'.$site->id);
    expect($c->meta)->toHaveKey('signal');
    expect($c->meta['signal']['load_1m_avg'])->toBe(5.0);
});

test('octane recommended runner skips when site already uses octane', function () {
    [, $server] = userWithServer();

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

    $runner = app(OctaneRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('octane recommended runner skips non laravel sites', function () {
    [, $server] = userWithServer();

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

    $runner = app(OctaneRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('octane recommended runner skips when load below threshold', function () {
    [, $server] = userWithServer();

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

    $runner = app(OctaneRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('octane recommended writes finding with kind suggestion via coordinator', function () {
    [, $server] = userWithServer();

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
    seedStackSummary($server, ['nginx', 'php-fpm']);

    app(InsightRunCoordinator::class)->runForSite($site->fresh());

    $finding = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('site_id', $site->id)
        ->where('insight_key', 'octane_recommended')
        ->first();

    expect($finding)->not->toBeNull();
    expect($finding->kind)->toBe(InsightFinding::KIND_SUGGESTION);
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->severity)->toBe(InsightFinding::SEVERITY_INFO);
});

test('octane recommended runner skips when too few samples', function () {
    [, $server] = userWithServer();

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

    $runner = app(OctaneRecommendedInsightRunner::class);
    expect($runner->run($server, $site, []))->toBe([]);
});

test('ignore finding marks suggestion as ignored and records user', function () {
    [$user, $server] = userWithServer();

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
    expect($finding->status)->toBe(InsightFinding::STATUS_IGNORED);
    expect($finding->ignored_at)->not->toBeNull();
    expect($finding->ignored_by_user_id)->toBe($user->id);
});

test('ignore action is a noop for problem findings', function () {
    [$user, $server] = userWithServer();

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
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN, 'Problems must not be silenced via ignore — they need to be fixed or auto-resolved');
    expect($finding->ignored_at)->toBeNull();
});

test('ignore finding writes audit log', function () {
    [$user, $server] = userWithServer();

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'octane_recommended',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'a-1',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 't',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('ignoreFinding', $finding->id);

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'action' => 'insight.ignored',
    ]);
});

test('acknowledge finding writes audit log', function () {
    [$user, $server] = userWithServer();

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'cpu_ram_usage',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'a-1',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_CRITICAL,
        'title' => 't',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('acknowledgeFinding', $finding->id);

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'action' => 'insight.acknowledged',
    ]);
});

test('rerun single check dispatches run job with only key filter', function () {
    [$user, $server] = userWithServer();

    Bus::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('rerunSingleCheck', 'cpu_ram_usage');

    Bus::assertDispatched(RunServerInsightsJob::class, function ($job) use ($server) {
        return $job->serverId === $server->id && $job->onlyKey === 'cpu_ram_usage';
    });
});

test('rerun single check refuses unknown keys', function () {
    [$user, $server] = userWithServer();

    Bus::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('rerunSingleCheck', 'definitely_not_a_real_insight_key');

    Bus::assertNotDispatched(RunServerInsightsJob::class);
});

test('run server insights job with only key skips health score recompute', function () {
    [, $server] = userWithServer();

    // We can't easily assert the health-score side effect without coupling to its
    // implementation, but we can confirm the job invokes the coordinator with onlyKey.
    $coordinatorState = ['only_key_seen' => null];
    $stubCoord = new class($coordinatorState) extends InsightRunCoordinator
    {
        public function __construct(array &$state)
        {
            $this->state = &$state;
        }

        public function runForServer(Server $server, ?string $onlyKey = null, ?callable $onProgress = null): void
        {
            $this->state['only_key_seen'] = $onlyKey;
        }
    };
    $this->app->instance(InsightRunCoordinator::class, $stubCoord);

    Bus::dispatchSync(new RunServerInsightsJob($server->id, 'cpu_ram_usage'));

    expect($coordinatorState['only_key_seen'])->toBe('cpu_ram_usage');
});
test('unacknowledge finding clears breadcrumbs and writes audit log', function () {
    [$user, $server] = userWithServer();

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'cpu_ram_usage',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'a-1',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_CRITICAL,
        'title' => 'High CPU',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now()->subHour(),
        'resolved_at' => null,
        'acknowledged_at' => now()->subMinutes(5),
        'acknowledged_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('unacknowledgeFinding', $finding->id);

    $finding->refresh();
    expect($finding->acknowledged_at)->toBeNull();
    expect($finding->acknowledged_by_user_id)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'action' => 'insight.unacknowledged',
    ]);
});

test('unacknowledge does not touch findings that were never acknowledged', function () {
    [$user, $server] = userWithServer();

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'cpu_ram_usage',
        'kind' => InsightFinding::KIND_PROBLEM,
        'dedupe_hash' => 'a-2',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_CRITICAL,
        'title' => 'High CPU',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
        // No acknowledged_at — calling unacknowledge should be a no-op.
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('unacknowledgeFinding', $finding->id);

    $this->assertDatabaseMissing('audit_logs', [
        'organization_id' => $server->organization_id,
        'action' => 'insight.unacknowledged',
    ]);
});

test('unignore finding restores to open and clears breadcrumbs', function () {
    [$user, $server] = userWithServer();

    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'octane_recommended',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'u-1',
        'status' => InsightFinding::STATUS_IGNORED,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'Consider Octane',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now()->subDay(),
        'resolved_at' => null,
        'ignored_at' => now()->subHours(2),
        'ignored_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->assertViewHas('ignoredSuggestions', fn ($c) => $c->count() === 1)
        // Ignored-recommendations card only renders inside the dismissed
        // tab; switch the workspace tab before asserting the text.
        ->call('setTab', 'dismissed')
        ->assertSee('Ignored recommendations')
        ->assertSee('Restore')
        ->call('unignoreFinding', $finding->id);

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->ignored_at)->toBeNull();
    expect($finding->ignored_by_user_id)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'action' => 'insight.unignored',
    ]);
});

test('unignore is a noop for non ignored findings', function () {
    [$user, $server] = userWithServer();

    // An OPEN finding should not be touched by unignoreFinding.
    $finding = InsightFinding::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'team_id' => null,
        'insight_key' => 'octane_recommended',
        'kind' => InsightFinding::KIND_SUGGESTION,
        'dedupe_hash' => 'u-2',
        'status' => InsightFinding::STATUS_OPEN,
        'severity' => InsightFinding::SEVERITY_INFO,
        'title' => 'x',
        'body' => '',
        'meta' => [],
        'correlation' => null,
        'detected_at' => now(),
        'resolved_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceInsights::class, ['server' => $server])
        ->call('unignoreFinding', $finding->id);

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
});

test('ignored suggestion does not reopen within cooldown', function () {
    [, $server] = userWithServer();

    registerStubInsight('stub_suggestion_cooldown', requires: [], kind: InsightFinding::KIND_SUGGESTION);
    config()->set('insights.insights.stub_suggestion_cooldown.cooldown_days', 30);

    // Initial run creates the suggestion.
    app(InsightRunCoordinator::class)->runForServer($server->fresh());
    $finding = InsightFinding::query()
        ->where('insight_key', 'stub_suggestion_cooldown')
        ->first();
    expect($finding)->not->toBeNull();

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
    expect($finding->status)->toBe(InsightFinding::STATUS_IGNORED);
    expect(InsightFinding::query()->where('insight_key', 'stub_suggestion_cooldown')->count())->toBe(1, 'Cooldown must not create a duplicate row');
});

test('ignored suggestion reopens after cooldown expires', function () {
    [, $server] = userWithServer();

    registerStubInsight('stub_suggestion_cooldown_expired', requires: [], kind: InsightFinding::KIND_SUGGESTION);
    config()->set('insights.insights.stub_suggestion_cooldown_expired.cooldown_days', 7);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());
    $finding = InsightFinding::query()
        ->where('insight_key', 'stub_suggestion_cooldown_expired')
        ->first();
    expect($finding)->not->toBeNull();

    // Ignored 8 days ago — past the 7-day cooldown.
    $finding->forceFill([
        'status' => InsightFinding::STATUS_IGNORED,
        'ignored_at' => now()->subDays(8),
        'ignored_by_user_id' => $server->user_id,
    ])->save();

    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->ignored_at)->toBeNull('Ignore breadcrumbs cleared on reopen so a future ignore restarts the cooldown');
    expect($finding->ignored_by_user_id)->toBeNull();
});

test('workspace insights renders suggestions in recommendations section', function () {
    [$user, $server] = userWithServer();

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
});

test('workspace banner excludes suggestions even when critical', function () {
    [$user, $server] = userWithServer();

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
});

test('apply fix job dispatches through handler and resolves problem when recheck passes', function () {
    [$user, $server] = userWithServer();

    // Stub handler that captures invocations.
    $handler = new class implements InsightFixActionInterface
    {
        public bool $preflightCalled = false;

        public bool $applyCalled = false;

        public function preflight($server, $site, $finding, array $params): ?string
        {
            $this->preflightCalled = true;

            return null;
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            $this->applyCalled = true;

            return FixResult::success('did the thing');
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    registerStubInsightWithHandler('stub_problem_with_fix', $handlerClass);

    // Recorder will run the registered runner and find no candidate → resolves the finding.
    // For this test, the runner emits one finding first, then on recheck the runner returns nothing.
    // Easiest path: the recorder writes a finding; the recheck runs the same stub which returns
    // an empty list when a one-shot flag is flipped. We set up the runner to read a flag.
    config()->set('insights.test.stub_problem_with_fix_should_emit', true);
    setStubRunnerEmits('stub_problem_with_fix', true);

    // Initial run: emit candidate → finding open.
    app(InsightRunCoordinator::class)->runForServer($server->fresh());
    $finding = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_problem_with_fix')
        ->where('status', InsightFinding::STATUS_OPEN)
        ->first();
    expect($finding)->not->toBeNull();

    // After fix: runner returns no candidate so recheck closes the finding.
    setStubRunnerEmits('stub_problem_with_fix', false);

    ApplyInsightFixJob::dispatch($finding->id, $user->id);

    expect($handler->preflightCalled)->toBeTrue();
    expect($handler->applyCalled)->toBeTrue();

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_RESOLVED);
    expect($finding->meta['fix_applied_at'] ?? null)->not->toBeNull();
    expect($finding->meta['fix_applied_by'] ?? null)->toBe($user->id);
});
test('apply fix job records failure when recheck still fails', function () {
    [$user, $server] = userWithServer();

    $handler = new class implements InsightFixActionInterface
    {
        public function preflight($server, $site, $finding, array $params): ?string
        {
            return null;
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            return FixResult::success('shell ran but condition persists');
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    registerStubInsightWithHandler('stub_problem_recheck_fails', $handlerClass);
    setStubRunnerEmits('stub_problem_recheck_fails', true);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());
    $finding = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_problem_recheck_fails')
        ->where('status', InsightFinding::STATUS_OPEN)
        ->first();
    expect($finding)->not->toBeNull();

    // Runner still emits → recheck won't clear the finding.
    ApplyInsightFixJob::dispatch($finding->id, $user->id);

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->meta['fix_failure_reason'] ?? null)->toBe('recheck_still_failing');
});

test('apply fix job records refusal from preflight without running apply', function () {
    [$user, $server] = userWithServer();

    $handler = new class implements InsightFixActionInterface
    {
        public bool $applyCalled = false;

        public function preflight($server, $site, $finding, array $params): ?string
        {
            return 'not enough RAM headroom';
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            $this->applyCalled = true;

            return FixResult::success();
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    registerStubInsightWithHandler('stub_problem_refusal', $handlerClass);
    setStubRunnerEmits('stub_problem_refusal', true);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());
    $finding = InsightFinding::query()
        ->where('insight_key', 'stub_problem_refusal')
        ->first();
    expect($finding)->not->toBeNull();

    ApplyInsightFixJob::dispatch($finding->id, $user->id);

    expect($handler->applyCalled)->toBeFalse();
    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_OPEN);
    expect($finding->meta['fix_refusal_reason'] ?? null)->toBe('not enough RAM headroom');
});
test('apply fix job resolves suggestion without recheck', function () {
    [$user, $server] = userWithServer();

    $handler = new class implements InsightFixActionInterface
    {
        public function preflight($server, $site, $finding, array $params): ?string
        {
            return null;
        }

        public function apply($server, $site, $finding, array $params, ?callable $onOutput = null): FixResult
        {
            return FixResult::success();
        }
    };
    $handlerClass = get_class($handler);
    $this->app->instance($handlerClass, $handler);

    registerStubInsightWithHandler('stub_suggestion_with_fix', $handlerClass, kind: InsightFinding::KIND_SUGGESTION);
    setStubRunnerEmits('stub_suggestion_with_fix', true);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());
    $finding = InsightFinding::query()
        ->where('insight_key', 'stub_suggestion_with_fix')
        ->first();
    expect($finding)->not->toBeNull();

    // Even though the runner still emits, the suggestion lifecycle resolves on apply success
    // without rechecking — windowed signals don't clear instantly.
    ApplyInsightFixJob::dispatch($finding->id, $user->id);

    $finding->refresh();
    expect($finding->status)->toBe(InsightFinding::STATUS_RESOLVED);
});

/**
 * Register a synthetic insight with a stub runner whose emit/no-emit state is
 * controlled by setStubRunnerEmits(). Wires the named handler class into config.
 */
function registerStubInsightWithHandler(string $key, string $handlerClass, string $kind = InsightFinding::KIND_PROBLEM): void
{
    $runner = new class($key, $kind) implements InsightRunnerInterface
    {
        public function __construct(private string $key, private string $kind) {}

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
    app()->instance($runnerClass, $runner);

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

function setStubRunnerEmits(string $key, bool $emits): void
{
    config()->set('insights.test.stub_runner_emits.'.$key, $emits);
}

test('suggestions do not dispatch notification events', function () {
    [$owner, $server] = userWithServer();

    $channel = NotificationChannel::factory()->forUser($owner)->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);
    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Server::class,
        'subscribable_id' => $server->id,
        'event_key' => InsightsNotificationDispatcher::EVENT_KEY,
    ]);

    Http::fake();

    registerStubInsight('stub_suggestion_no_notify', requires: [], kind: InsightFinding::KIND_SUGGESTION);

    app(InsightRunCoordinator::class)->runForServer($server->fresh());

    $finding = InsightFinding::query()
        ->where('server_id', $server->id)
        ->where('insight_key', 'stub_suggestion_no_notify')
        ->first();
    expect($finding)->not->toBeNull();

    $this->assertDatabaseMissing('notification_events', [
        'event_key' => InsightsNotificationDispatcher::EVENT_KEY,
        'subject_type' => InsightFinding::class,
        'subject_id' => $finding->id,
    ]);
    Http::assertNothingSent();
});

/**
 * Register a synthetic insight with an inline stub runner that always emits one candidate.
 * Default-enabled so InsightSettingsRepository::isInsightEnabled() returns true without
 * needing a stored InsightSetting row.
 *
 * @param  list<string>  $requires
 */
function registerStubInsight(string $key, array $requires, string $kind = InsightFinding::KIND_PROBLEM): void
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
    app()->instance($runnerClass, $runner);

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
function seedStackSummary(Server $server, array $expectedServices): void
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

    $hasPhp = in_array('php-fpm', $expectedServices, true) || in_array('php', $expectedServices, true);

    $run->artifacts()->create([
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Installed stack',
        'metadata' => [
            'webserver' => in_array('nginx', $expectedServices, true) ? 'nginx' : 'none',
            'database' => in_array('mysql', $expectedServices, true) ? 'mysql' : (in_array('postgresql', $expectedServices, true) ? 'postgresql' : 'none'),
            'cache_service' => 'none',
            'php_version' => $hasPhp ? '8.3' : 'none',
            'expected_services' => $expectedServices,
        ],
        'content' => '{}',
    ]);
}
