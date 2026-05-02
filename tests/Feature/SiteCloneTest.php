<?php

namespace Tests\Feature;

use App\Jobs\CloneSiteJob;
use App\Livewire\Sites\SiteClone as SitesClone;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteCloneTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

    private function userWithOrganization(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    private function readyServer(User $user, Organization $org): Server
    {
        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => self::FAKE_SSH_KEY,
        ]);
    }

    public function test_guest_cannot_view_clone_page(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = $this->readyServer($user, $org);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->get(route('sites.clone', [$server, $site]))
            ->assertRedirect();
    }

    public function test_clone_page_renders_for_authorized_user(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = $this->readyServer($user, $org);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.com',
            'is_primary' => true,
        ]);

        $this->actingAs($user)
            ->get(route('sites.clone', [$server, $site]))
            ->assertOk()
            ->assertSee('Clone site', false);
    }

    public function test_start_clone_dispatches_clone_job(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = $this->readyServer($user, $org);
        $dest = $this->readyServer($user, $org);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'source.example.com',
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(SitesClone::class, ['server' => $server, 'site' => $site])
            ->set('clone_hostname', 'dest.example.com')
            ->set('clone_site_name', 'Cloned app')
            ->set('destination_server_id', (string) $dest->id)
            ->call('startClone')
            ->assertHasNoErrors()
            ->assertRedirect(route('servers.sites', $dest));

        Queue::assertPushed(CloneSiteJob::class, function (CloneSiteJob $job) use ($site, $dest, $user): bool {
            return $job->sourceSiteId === (string) $site->id
                && $job->destinationServerId === (string) $dest->id
                && $job->primaryHostname === 'dest.example.com'
                && $job->siteName === 'Cloned app'
                && $job->userId === (string) $user->id;
        });
    }

    public function test_invalid_hostname_is_rejected(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = $this->readyServer($user, $org);
        $dest = $this->readyServer($user, $org);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'source.example.com',
            'is_primary' => true,
        ]);

        $sitesBefore = Site::query()->count();

        Livewire::actingAs($user)
            ->test(SitesClone::class, ['server' => $server, 'site' => $site])
            ->set('clone_hostname', 'not-a-valid-hostname')
            ->set('clone_site_name', 'X')
            ->set('destination_server_id', (string) $dest->id)
            ->call('startClone')
            ->assertHasErrors(['clone_hostname']);

        $this->assertSame($sitesBefore, Site::query()->count());
    }
}
