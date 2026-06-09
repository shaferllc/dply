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

/** Count of `select ... from "servers" where "id" = ? ... limit 1` row lookups. */
function serverRowLookupCount(): int
{
    return collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "servers"')
            && str_contains($sql, '"id" =')
            && str_contains($sql, 'limit 1'))
        ->count();
}

// On a full-page load the server row is fetched once by route-model binding.
// render() must not refresh it again (that was the duplicate query), so the
// total stays at exactly one `select * from "servers" where "id" = ? limit 1`.

test('manage page loads the server row only once', function (): void {
    [$user, $server] = workspaceRowDedupSetup();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('servers.manage', ['server' => $server, 'section' => 'overview']))
        ->assertOk();

    $count = serverRowLookupCount();
    DB::disableQueryLog();

    expect($count)->toBe(1);
});

test('overview page loads the server row only once', function (): void {
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
