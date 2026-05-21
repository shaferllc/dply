<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Livewire\Serverless\Index as ServerlessIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    }

    private function makeFunction(Organization $org, string $name): Site
    {
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $this->user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $this->user->id,
            'name' => $name,
            'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
        ]);
    }

    public function test_it_shows_the_empty_state_with_no_functions(): void
    {
        Livewire::actingAs($this->user)
            ->test(ServerlessIndex::class)
            ->assertSee('No functions yet');
    }

    public function test_it_lists_the_organizations_functions(): void
    {
        $this->makeFunction($this->org, 'Orders API');

        Livewire::actingAs($this->user)
            ->test(ServerlessIndex::class)
            ->assertSee('Orders API')
            ->assertDontSee('No functions yet');
    }

    public function test_it_does_not_list_another_organizations_functions(): void
    {
        $this->makeFunction(Organization::factory()->create(), 'Someone Elses Function');

        Livewire::actingAs($this->user)
            ->test(ServerlessIndex::class)
            ->assertDontSee('Someone Elses Function');
    }
}
