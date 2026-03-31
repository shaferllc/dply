<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\InsightFinding;
use App\Models\InsightHealthSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Server, 2: string}
     */
    protected function orgServerAndToken(array $abilities = ['insights.read', 'servers.read']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '10.0.0.1',
        ]);
        ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'insights-test', null, $abilities);

        return [$org, $server, $plain];
    }

    public function test_server_insights_returns_open_findings(): void
    {
        [, $server, $plain] = $this->orgServerAndToken();

        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'cpu_ram_usage',
            'dedupe_hash' => 'threshold',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'High CPU',
            'body' => 'test',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        $res = $this->getJson('/api/v1/servers/'.$server->id.'/insights', [
            'Authorization' => 'Bearer '.$plain,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.0.insight_key', 'cpu_ram_usage');
        $res->assertJsonPath('data.0.severity', 'warning');
    }

    public function test_org_summary_includes_severity_counts_and_health_score(): void
    {
        [$org, $server, $plain] = $this->orgServerAndToken();

        InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'cpu_ram_usage',
            'dedupe_hash' => 'threshold',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_CRITICAL,
            'title' => 'CPU',
            'body' => null,
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        InsightHealthSnapshot::query()->create([
            'server_id' => $server->id,
            'score' => 72,
            'counts' => ['critical' => 1, 'warning' => 0, 'info' => 0, 'total' => 1],
            'captured_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/insights/summary', [
            'Authorization' => 'Bearer '.$plain,
        ]);

        $res->assertOk();
        $res->assertJsonPath('open_by_severity.critical', 1);
        $res->assertJsonPath('servers.0.health_score', 72);
    }

    public function test_server_insights_forbidden_for_other_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userB = User::factory()->create();
        $orgB->users()->attach($userB->id, ['role' => 'owner']);
        $serverA = Server::factory()->create([
            'organization_id' => $orgA->id,
            'user_id' => User::factory()->create()->id,
        ]);
        ['plaintext' => $plain] = ApiToken::createToken($userB, $orgB, 't', null, ['insights.read']);

        $this->getJson('/api/v1/servers/'.$serverA->id.'/insights', [
            'Authorization' => 'Bearer '.$plain,
        ])->assertForbidden();
    }
}
