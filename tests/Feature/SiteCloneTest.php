<?php


namespace Tests\Feature\SiteCloneTest;
use App\Jobs\CloneSiteJob;
use App\Livewire\Sites\SiteClone as SitesClone;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

function userWithOrganization(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function readyServer(User $user, Organization $org): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => FAKE_SSH_KEY,
    ]);
}

test('guest cannot view clone page', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = readyServer($user, $org);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $this->get(route('sites.clone', [$server, $site]))
        ->assertRedirect();
});

test('clone page renders for authorized user', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = readyServer($user, $org);
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
});

test('start clone dispatches clone job', function () {
    Queue::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = readyServer($user, $org);
    $dest = readyServer($user, $org);
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
});

test('invalid hostname is rejected', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = readyServer($user, $org);
    $dest = readyServer($user, $org);
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

    expect(Site::query()->count())->toBe($sitesBefore);
});