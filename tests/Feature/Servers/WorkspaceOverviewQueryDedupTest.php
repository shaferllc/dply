<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\WorkspaceOverviewQueryDedupTest;

use App\Livewire\Servers\WorkspaceOverview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Every flagged card below pulls "the latest metric snapshot" for the same
// server during a single overview render — alongside the component itself and
// Server::billingTier(). They must all reuse one lookup.
usesFeatures('workspace.health', 'workspace.server_cost', 'workspace.release_hygiene', 'workspace.patch_advisor');

function overviewQueryDedupSetup(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $org);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
    ]);

    // A couple of samples so a missing "latest" filter would be obvious.
    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now()->subHour(),
        'payload' => ['cpu_pct' => 10, 'mem_pct' => 12, 'cpu_count' => 2, 'mem_total_kb' => 2_097_152],
    ]);
    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_pct' => 20, 'mem_pct' => 25, 'cpu_count' => 2, 'mem_total_kb' => 2_097_152],
    ]);

    return [$user, $server];
}

test('overview render reads the latest metric snapshot only once', function (): void {
    [$user, $server] = overviewQueryDedupSetup();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server]);

    $snapshotQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "server_metric_snapshots"'));

    DB::disableQueryLog();

    // Component card, health cockpit, cost card (capacity + hardware), release
    // hygiene disk probe, and Server::billingTier() all fan out to the same
    // memoized relation, so the latest snapshot is read exactly once.
    expect($snapshotQueries)->toHaveCount(1);
});
