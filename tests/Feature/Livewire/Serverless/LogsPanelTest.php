<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Livewire\Serverless\LogsPanel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LogsPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * @param  array<string, mixed>  $functionsMeta
     */
    private function functionSite(array $functionsMeta): Site
    {
        $this->user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($this->user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $org->id,
            'meta' => array_merge(['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS], $functionsMeta),
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_it_lists_recent_activations(): void
    {
        Http::fake([
            'https://faas.example/api/v1/namespaces/_/activations*' => Http::response([
                [
                    'activationId' => 'act-1',
                    'name' => 'orders-api',
                    'duration' => 40,
                    'start' => 1779200000000,
                    'response' => ['status' => 'success', 'success' => true],
                    'annotations' => [['key' => 'initTime', 'value' => 250]],
                    'logs' => ['hello from the function'],
                ],
                [
                    'activationId' => 'act-2',
                    'name' => 'orders-api',
                    'duration' => 60,
                    'start' => 1779200001000,
                    'response' => ['status' => 'application error', 'success' => false],
                    'logs' => [],
                ],
            ], 200),
        ]);

        $site = $this->functionSite([
            'digitalocean_functions' => ['api_host' => 'https://faas.example', 'access_key' => 'id:secret'],
        ]);

        Livewire::actingAs($this->user)
            ->test(LogsPanel::class, ['site' => $site])
            ->assertSee('orders-api')
            ->assertSee('hello from the function')
            // Metrics: 2 invocations, 1 error (50%), avg 50ms, 1 cold start.
            ->assertSee('Error rate')
            ->assertSee('50%')
            ->assertSee('Cold starts');
    }

    public function test_it_explains_when_the_host_is_not_provisioned(): void
    {
        $site = $this->functionSite([]);

        Livewire::actingAs($this->user)
            ->test(LogsPanel::class, ['site' => $site])
            ->assertSee('not provisioned');
    }
}
