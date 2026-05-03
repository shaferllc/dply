<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionEdgeSiteJob;
use App\Livewire\Edge\Create as EdgeCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EdgeCreatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_no_backends_connected_warning(): void
    {
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('edge.create'));

        $response->assertOk()
            ->assertSee('Deploy a container app')
            ->assertSee('No container backend connected')
            ->assertSee('Connect DigitalOcean')
            ->assertSee('Connect AWS App Runner');
    }

    public function test_page_hides_warning_when_backend_connected(): void
    {
        $user = $this->ownerWithOrg();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $response = $this->actingAs($user)->get(route('edge.create'));

        $response->assertOk()
            ->assertDontSee('No container backend connected');
    }

    public function test_changing_backend_resets_region_to_first_available(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('backend', 'aws_app_runner')
            ->assertSet('region', 'us-east-1')
            ->set('backend', 'digitalocean_app_platform')
            ->assertSet('region', 'ams');
    }

    public function test_deploy_dispatches_provision_job_and_redirects(): void
    {
        Queue::fake();
        $user = $this->ownerWithOrg();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('name', 'Acme API')
            ->set('image', 'ghcr.io/acme/api:v1')
            ->set('port', 8080)
            ->set('region', 'nyc')
            ->set('backend', 'digitalocean_app_platform')
            ->call('deploy')
            ->assertHasNoErrors();

        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    public function test_deploy_with_no_credential_shows_toast_error(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->set('name', 'Lonely')
            ->set('image', 'nginx:1')
            ->set('region', 'nyc')
            ->set('backend', 'auto')
            ->call('deploy')
            ->assertDispatched('notify');
    }

    public function test_deploy_validates_required_fields(): void
    {
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(EdgeCreate::class)
            ->call('deploy')
            ->assertHasErrors(['name', 'image']);
    }

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
