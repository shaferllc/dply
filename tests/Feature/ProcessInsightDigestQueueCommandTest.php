<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InsightDigestQueue;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessInsightDigestQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_drains_daily_queue_and_skips_weekly_orgs(): void
    {
        Mail::fake();

        $dailyOrg = $this->makeOrgWithFrequency('daily');
        $weeklyOrg = $this->makeOrgWithFrequency('weekly');
        $dailyQueueRow = $this->queueFinding($dailyOrg);
        $weeklyQueueRow = $this->queueFinding($weeklyOrg);

        Artisan::call('dply:process-insight-digest-queue');

        $this->assertNull(InsightDigestQueue::query()->find($dailyQueueRow->id));
        $this->assertNotNull(InsightDigestQueue::query()->find($weeklyQueueRow->id));
    }

    public function test_weekly_flag_drains_only_weekly_orgs(): void
    {
        Mail::fake();

        $dailyOrg = $this->makeOrgWithFrequency('daily');
        $weeklyOrg = $this->makeOrgWithFrequency('weekly');
        $dailyRow = $this->queueFinding($dailyOrg);
        $weeklyRow = $this->queueFinding($weeklyOrg);

        Artisan::call('dply:process-insight-digest-queue', ['--weekly' => true]);

        $this->assertNotNull(InsightDigestQueue::query()->find($dailyRow->id));
        $this->assertNull(InsightDigestQueue::query()->find($weeklyRow->id));
    }

    public function test_no_op_when_queue_is_empty(): void
    {
        Mail::fake();

        $exit = Artisan::call('dply:process-insight-digest-queue');

        $this->assertSame(0, $exit);
    }

    private function makeOrgWithFrequency(string $frequency): Organization
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->forceFill([
            'insights_preferences' => ['digest_frequency' => $frequency],
        ])->save();

        return $org;
    }

    private function queueFinding(Organization $org): InsightDigestQueue
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $finding = InsightFinding::query()->create([
            'server_id' => $server->id,
            'insight_key' => 'noisy-program',
            'dedupe_hash' => bin2hex(random_bytes(16)),
            'status' => 'open',
            'severity' => 'warning',
            'title' => 'Long-running supervisor program',
            'detected_at' => now(),
        ]);

        return InsightDigestQueue::query()->create([
            'insight_finding_id' => $finding->id,
            'organization_id' => $org->id,
        ]);
    }
}
