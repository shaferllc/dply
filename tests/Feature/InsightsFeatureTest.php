<?php

namespace Tests\Feature;

use App\Jobs\RunServerInsightsJob;
use App\Livewire\Servers\WorkspaceInsights;
use App\Models\InsightFinding;
use App\Models\InsightSetting;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\User;
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
}
