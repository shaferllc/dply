<?php

namespace Tests\Feature\Livewire\Serverless;

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
use Tests\TestCase;

class RollbackPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function functionSite(array $history): Site
    {
        $this->user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($this->user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $this->user->id,
            'meta' => ['serverless' => ['artifact_history' => $history]],
        ]);
    }

    public function test_it_rolls_back_to_an_earlier_artifact(): void
    {
        Bus::fake();

        $artifact = storage_path('framework/testing/rollback-'.uniqid().'.zip');
        File::ensureDirectoryExists(dirname($artifact));
        File::put($artifact, 'zip-bytes');

        $site = $this->functionSite([
            ['artifact_path' => '/tmp/current.zip', 'revision_id' => '8', 'deployed_at' => now()->toIso8601String()],
            ['artifact_path' => $artifact, 'revision_id' => '7', 'deployed_at' => now()->subHour()->toIso8601String()],
        ]);

        Livewire::actingAs($this->user)
            ->test(RollbackPanel::class, ['site' => $site])
            ->call('rollback', 1);

        Bus::assertDispatched(RollbackServerlessFunctionJob::class,
            fn ($job) => $job->siteId === $site->id && $job->artifactPath === $artifact);

        File::delete($artifact);
    }

    public function test_it_will_not_roll_back_to_the_live_deploy(): void
    {
        Bus::fake();
        $site = $this->functionSite([
            ['artifact_path' => '/tmp/current.zip', 'revision_id' => '8'],
        ]);

        Livewire::actingAs($this->user)
            ->test(RollbackPanel::class, ['site' => $site])
            ->call('rollback', 0);

        Bus::assertNotDispatched(RollbackServerlessFunctionJob::class);
    }
}
