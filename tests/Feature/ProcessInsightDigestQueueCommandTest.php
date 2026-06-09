<?php

declare(strict_types=1);

namespace Tests\Feature\ProcessInsightDigestQueueCommandTest;

use App\Models\InsightDigestQueue;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('drains daily queue and skips weekly orgs', function () {
    Mail::fake();

    $dailyOrg = makeOrgWithFrequency('daily');
    $weeklyOrg = makeOrgWithFrequency('weekly');
    $dailyQueueRow = queueFinding($dailyOrg);
    $weeklyQueueRow = queueFinding($weeklyOrg);

    Artisan::call('dply:process-insight-digest-queue');

    expect(InsightDigestQueue::query()->find($dailyQueueRow->id))->toBeNull();
    expect(InsightDigestQueue::query()->find($weeklyQueueRow->id))->not->toBeNull();
});
test('weekly flag drains only weekly orgs', function () {
    Mail::fake();

    $dailyOrg = makeOrgWithFrequency('daily');
    $weeklyOrg = makeOrgWithFrequency('weekly');
    $dailyRow = queueFinding($dailyOrg);
    $weeklyRow = queueFinding($weeklyOrg);

    Artisan::call('dply:process-insight-digest-queue', ['--weekly' => true]);

    expect(InsightDigestQueue::query()->find($dailyRow->id))->not->toBeNull();
    expect(InsightDigestQueue::query()->find($weeklyRow->id))->toBeNull();
});
test('no op when queue is empty', function () {
    Mail::fake();

    $exit = Artisan::call('dply:process-insight-digest-queue');

    expect($exit)->toBe(0);
});
function makeOrgWithFrequency(string $frequency): Organization
{
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->forceFill([
        'insights_preferences' => ['digest_frequency' => $frequency],
    ])->save();

    return $org;
}
function queueFinding(Organization $org): InsightDigestQueue
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
