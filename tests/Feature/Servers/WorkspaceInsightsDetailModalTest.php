<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\ApplyInsightFixJob;
use App\Livewire\Servers\WorkspaceInsights;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\FixResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class WorkspaceInsightsDetailModalTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['workspace.insights'];

    /**
     * @return array{0: User, 1: Server, 2: Organization}
     */
    private function userServerOrg(): array
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

    private function makeFinding(Server $server, array $overrides = []): InsightFinding
    {
        return InsightFinding::query()->create(array_merge([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'package_security_updates',
            'kind' => InsightFinding::KIND_PROBLEM,
            'dedupe_hash' => 'd_'.uniqid(),
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_CRITICAL,
            'title' => 'Security updates available',
            'body' => '96 of 154 upgradable packages are security updates.',
            'meta' => ['signal' => ['security_count' => 96, 'upgradable_count' => 154]],
            'correlation' => null,
            'detected_at' => now(),
        ], $overrides));
    }

    public function test_opening_detail_loads_the_finding_and_decorates_actions(): void
    {
        [$user, $server] = $this->userServerOrg();
        $finding = $this->makeFinding($server);

        // Register a fake fix handler so canRunFix flips on.
        config()->set('insights.insights.package_security_updates.fix', [
            'handler' => StubFixHandler::class,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('openFindingDetail', $finding->id);

        $component->assertSet('detailFindingId', $finding->id);

        $detail = $component->instance()->selectedFindingDetail;
        $this->assertNotNull($detail);
        $this->assertSame($finding->id, $detail['finding']->id);
        $this->assertSame('idle', $detail['fixHistory']['run_status']);
        $this->assertTrue($detail['actions']['canRunFix']);
        $this->assertTrue($detail['actions']['canRerun']);
        $this->assertTrue($detail['actions']['canAcknowledge']);
        $this->assertFalse($detail['actions']['canIgnore']);
        $this->assertSame(['security_count' => 96, 'upgradable_count' => 154], $detail['signalRows']);
    }

    public function test_opening_detail_ignores_findings_belonging_to_another_server(): void
    {
        [$user, $server] = $this->userServerOrg();

        $anotherServer = Server::factory()->create([
            'organization_id' => $server->organization_id,
            'user_id' => $user->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '127.0.0.2',
        ]);
        $strayFinding = $this->makeFinding($anotherServer);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('openFindingDetail', $strayFinding->id)
            ->assertSet('detailFindingId', null);
    }

    public function test_acknowledge_action_closes_the_detail_modal_when_it_targets_the_open_finding(): void
    {
        [$user, $server] = $this->userServerOrg();
        $finding = $this->makeFinding($server);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('openFindingDetail', $finding->id)
            ->assertSet('detailFindingId', $finding->id)
            ->call('acknowledgeFinding', $finding->id)
            ->assertSet('detailFindingId', null);
    }

    public function test_run_fix_dispatches_job_and_stamps_run_started_at(): void
    {
        Bus::fake([ApplyInsightFixJob::class]);

        [$user, $server] = $this->userServerOrg();
        $finding = $this->makeFinding($server);

        config()->set('insights.insights.package_security_updates.fix', [
            'handler' => StubFixHandler::class,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('openFindingDetail', $finding->id)
            ->call('runFix', $finding->id)
            // Modal stays open so the operator can watch progress.
            ->assertSet('detailFindingId', $finding->id);

        Bus::assertDispatched(ApplyInsightFixJob::class, function (ApplyInsightFixJob $job) use ($finding, $user): bool {
            return $job->insightFindingId === $finding->id && $job->userId === (string) $user->id;
        });

        $finding->refresh();
        $this->assertArrayHasKey('fix_run_started_at', $finding->meta);
        $this->assertSame($user->id, $finding->meta['fix_run_started_by']);
    }

    public function test_run_fix_clears_prior_terminal_meta_so_status_flips_back_to_queued(): void
    {
        Bus::fake([ApplyInsightFixJob::class]);

        [$user, $server] = $this->userServerOrg();

        config()->set('insights.insights.package_security_updates.fix', [
            'handler' => StubFixHandler::class,
        ]);

        $finding = $this->makeFinding($server, [
            'meta' => [
                'signal' => ['security_count' => 1],
                'fix_failed_at' => now()->subHour()->toIso8601String(),
                'fix_failed_by' => $user->id,
                'fix_failure_reason' => 'previous_run_blew_up',
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('openFindingDetail', $finding->id);

        $detailBefore = $component->instance()->selectedFindingDetail;
        $this->assertSame('failed', $detailBefore['fixHistory']['run_status']);

        $component->call('runFix', $finding->id);

        $finding->refresh();
        $this->assertArrayNotHasKey('fix_failed_at', $finding->meta);
        $this->assertArrayNotHasKey('fix_failure_reason', $finding->meta);
        $this->assertArrayHasKey('fix_run_started_at', $finding->meta);
    }

    public function test_run_fix_is_a_noop_when_no_fix_handler_is_configured(): void
    {
        Bus::fake([ApplyInsightFixJob::class]);

        [$user, $server] = $this->userServerOrg();
        $finding = $this->makeFinding($server, [
            'insight_key' => 'cpu_ram_usage', // no .fix block in real config for this scenario
        ]);
        config()->set('insights.insights.cpu_ram_usage.fix', null);

        Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('runFix', $finding->id);

        Bus::assertNotDispatched(ApplyInsightFixJob::class);
        $finding->refresh();
        $this->assertArrayNotHasKey('fix_run_started_at', $finding->meta ?? []);
    }

    public function test_correlation_pivot_loads_a_sibling_finding(): void
    {
        [$user, $server] = $this->userServerOrg();
        $first = $this->makeFinding($server, ['title' => 'First finding']);
        $second = $this->makeFinding($server, ['title' => 'Second finding']);

        // Cross-link first → second.
        $first->forceFill(['correlation' => [$second->id]])->save();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceInsights::class, ['server' => $server])
            ->call('openFindingDetail', $first->id);

        $detail = $component->instance()->selectedFindingDetail;
        $this->assertCount(1, $detail['correlationFindings']);
        $this->assertSame($second->id, $detail['correlationFindings']->first()->id);

        $component->call('openFindingDetail', $second->id)
            ->assertSet('detailFindingId', $second->id);
    }
}

/**
 * Minimal stand-in so config('insights.insights.[key].fix.handler') resolves
 * to a class that satisfies the InsightFixActionInterface contract during
 * preflight checks. The job is faked in these tests so apply() never runs.
 */
class StubFixHandler implements InsightFixActionInterface
{
    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string
    {
        return null;
    }

    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        return FixResult::success('stub-applied');
    }
}
