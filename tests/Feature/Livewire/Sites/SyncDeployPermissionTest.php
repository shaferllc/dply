<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\SyncDeployPermissionTest;

use App\Livewire\Sites\DeploymentsList;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: Site}
 *
 * A user who can update the server, with two repo-sharing sites on it.
 */
function syncFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    $repo = 'git@github.com:acme/app.git';
    $primary = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'app',
        'git_repository_url' => $repo,
    ]);
    $worker = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'app-worker',
        'git_repository_url' => $repo,
    ]);

    return [$user, $primary, $worker];
}

function candidates(Site $site)
{
    $component = new DeploymentsList;
    $component->site = $site;

    return $component->getSyncCandidatesProperty();
}

test('sync candidates carry a fully-loaded server the update policy can authorize', function () {
    [$user, $primary, $worker] = syncFixture();

    $list = candidates($primary);

    // Both repo-sharing sites are candidates.
    expect($list->pluck('id')->sort()->values()->all())
        ->toBe(collect([$primary->id, $worker->id])->sort()->values()->all());

    // The eager-loaded server carries the columns ServerPolicy::view reads —
    // a partial server:id,name load would null these and skip every site.
    foreach ($list as $candidate) {
        expect($candidate->server->organization_id)->not->toBeNull();
        expect($candidate->server->user_id)->not->toBeNull();
        // The exact gate deployMultiple() runs per candidate.
        expect(Gate::forUser($user)->allows('update', $candidate))->toBeTrue();
    }
});

test('regression: a server:id,name partial load nulls the policy columns and denies update', function () {
    [$user, $primary] = syncFixture();

    // This is what the buggy eager-load produced.
    $partial = Site::query()->with('server:id,name')->findOrFail($primary->id);

    expect($partial->server->organization_id)->toBeNull();
    expect(Gate::forUser($user)->allows('update', $partial))->toBeFalse();
});
