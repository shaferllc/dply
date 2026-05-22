<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Livewire\Serverless\BackgroundPanel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackgroundPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($this->user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);
        $this->site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_toggle_enables_then_disables_background_processing(): void
    {
        Livewire::actingAs($this->user)
            ->test(BackgroundPanel::class, ['site' => $this->site])
            ->call('toggle');
        $this->assertTrue(data_get($this->site->fresh()->meta, 'serverless.background_enabled'));

        Livewire::actingAs($this->user)
            ->test(BackgroundPanel::class, ['site' => $this->site])
            ->call('toggle');
        $this->assertFalse(data_get($this->site->fresh()->meta, 'serverless.background_enabled'));
    }
}
