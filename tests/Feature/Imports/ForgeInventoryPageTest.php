<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Jobs\Imports\SyncForgeInventoryJob;
use App\Models\ForgeServer;
use App\Models\ForgeSite;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForgeInventoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/imports/forge')->assertRedirect(route('login', absolute: false));
    }

    public function test_page_shows_empty_state_when_no_forge_credential(): void
    {
        $user = $this->userWithOrganization();

        $this->actingAs($user)->get('/imports/forge')
            ->assertOk()
            ->assertSee('Connect Laravel Forge to see your inventory');
    }

    public function test_page_lists_forge_servers_and_sites(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'forge',
            'credentials' => ['api_token' => 'forge_x'],
        ]);
        $server = ForgeServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'agency-prod',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digitalocean',
            'server_type' => 's-2vcpu-4gb',
            'php_versions' => ['8.3'],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        ForgeSite::create([
            'forge_server_id' => $server->id,
            'source_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'php_version' => '8.3',
            'repository_url' => 'git@github.com:acme/app.git',
            'repository_branch' => 'main',
            'web_directory' => '/public',
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        ForgeSite::create([
            'forge_server_id' => $server->id,
            'source_id' => 101,
            'domain' => 'static.example.com',
            'site_type' => 'static',
            'php_version' => null,
            'repository_url' => null,
            'repository_branch' => null,
            'web_directory' => null,
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);

        $response = $this->actingAs($user)->get('/imports/forge');

        $response->assertOk()
            ->assertSee('agency-prod')
            ->assertSee('app.example.com')
            ->assertSee('static.example.com')
            ->assertSee('Eligible')
            ->assertSee('Unsupported in v1')
            ->assertSee('Migrate this server');
    }

    public function test_lazy_sync_dispatches_when_stale(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'forge',
        ]);

        $this->actingAs($user)->get('/imports/forge')->assertOk();

        Queue::assertPushed(SyncForgeInventoryJob::class, function (SyncForgeInventoryJob $job) use ($credential): bool {
            return $job->providerCredentialId === $credential->id;
        });
    }
}
