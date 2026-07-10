<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\WorkspaceServerRowQueryDedupTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function workspaceRowDedupSetup(): array
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

    return [$user, $server];
}

/**
 * Count of bare route-model-binding lookups:
 * `select ... from "servers" where "id" = ? limit 1`
 *
 * Org-scoped lookups (`where organization_id = ? and id = ?`) are expected
 * authorization checks and are excluded — this guard only catches render()
 * re-fetching the same unbound row.
 */
function serverRowLookupCount(): int
{
    return collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "servers"')
            && str_contains($sql, '"id" =')
            && str_contains($sql, 'limit 1')
            && ! str_contains($sql, 'organization_id'))
        ->count();
}

// /manage/overview redirects to the standalone Overview page; tools is the
// peer surface that still boots a full workspace Livewire page without a
// redirect. render() must not refresh the unbound server row again.

test('tools page loads the unbound server row only once', function (): void {
    [$user, $server] = workspaceRowDedupSetup();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('servers.tools', $server))
        ->assertOk();

    $count = serverRowLookupCount();
    DB::disableQueryLog();

    expect($count)->toBe(1);
});

test('overview page loads the unbound server row only once', function (): void {
    [$user, $server] = workspaceRowDedupSetup();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk();

    $count = serverRowLookupCount();
    DB::disableQueryLog();

    expect($count)->toBe(1);
});
