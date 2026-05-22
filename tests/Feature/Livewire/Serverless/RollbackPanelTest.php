<?php

namespace Tests\Feature\Livewire\Serverless\RollbackPanelTest;

use App\Jobs\RollbackServerlessFunctionJob;
use App\Livewire\Serverless\RollbackPanel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $history
 * @return array{0: User, 1: Site}
 */
function functionSite(array $history): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['serverless' => ['artifact_history' => $history]],
    ]);

    return [$user, $site];
}

test('it rolls back to an earlier artifact', function () {
    Bus::fake();

    $artifact = storage_path('framework/testing/rollback-'.uniqid().'.zip');
    File::ensureDirectoryExists(dirname($artifact));
    File::put($artifact, 'zip-bytes');

    [$user, $site] = functionSite([
        ['artifact_path' => '/tmp/current.zip', 'revision_id' => '8', 'deployed_at' => now()->toIso8601String()],
        ['artifact_path' => $artifact, 'revision_id' => '7', 'deployed_at' => now()->subHour()->toIso8601String()],
    ]);

    Livewire::actingAs($user)
        ->test(RollbackPanel::class, ['site' => $site])
        ->call('rollback', 1);

    Bus::assertDispatched(RollbackServerlessFunctionJob::class,
        fn ($job) => $job->siteId === $site->id && $job->artifactPath === $artifact);

    File::delete($artifact);
});

test('it will not roll back to the live deploy', function () {
    Bus::fake();
    [$user, $site] = functionSite([
        ['artifact_path' => '/tmp/current.zip', 'revision_id' => '8'],
    ]);

    Livewire::actingAs($user)
        ->test(RollbackPanel::class, ['site' => $site])
        ->call('rollback', 0);

    Bus::assertNotDispatched(RollbackServerlessFunctionJob::class);
});
