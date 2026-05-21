<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Jobs\ProvisionServerlessDatabaseJob;
use App\Livewire\Serverless\DatabasePanel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class DatabasePanelTest extends TestCase
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

    public function test_it_shows_the_provision_form_when_there_is_no_database(): void
    {
        Livewire::actingAs($this->user)
            ->test(DatabasePanel::class, ['site' => $this->site])
            ->assertSee('Provision database');
    }

    public function test_provision_records_intent_and_dispatches_the_job(): void
    {
        Bus::fake();

        Livewire::actingAs($this->user)
            ->test(DatabasePanel::class, ['site' => $this->site])
            ->set('engine', 'pg')
            ->set('size', 'db-s-1vcpu-1gb')
            ->call('provision');

        Bus::assertDispatched(ProvisionServerlessDatabaseJob::class);
        $this->assertSame('provisioning', data_get($this->site->fresh()->meta, 'serverless.database.status'));
        $this->assertSame('pg', data_get($this->site->fresh()->meta, 'serverless.database.engine'));
    }

    public function test_it_does_not_provision_a_second_database(): void
    {
        Bus::fake();
        $this->site->forceFill([
            'meta' => ['serverless' => ['database' => ['status' => 'online', 'engine' => 'pg']]],
        ])->save();

        Livewire::actingAs($this->user)
            ->test(DatabasePanel::class, ['site' => $this->site])
            ->call('provision');

        Bus::assertNotDispatched(ProvisionServerlessDatabaseJob::class);
    }
}
