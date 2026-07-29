<?php

declare(strict_types=1);

namespace Tests\Feature\SitePromotePageTest;

use App\Jobs\CloneSiteJob;
use App\Livewire\Sites\SitePromote;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.site_promote');

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

beforeEach(function (): void {
    // Fresh factory servers must count toward plan tier so promote isn't
    // blocked by the Free plan's 1-site ceiling (clone policy requires create).
    config(['subscription.standard.min_billable_age_days' => 0]);
});

/**
 * @return array{0: User, 1: Organization}
 */
function promoteUserWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

function promoteReadyServer(User $user, Organization $org): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => FAKE_SSH_KEY,
    ]);
}

test('promote page is hidden without feature flag', function (): void {
    Feature::define('workspace.site_promote', fn (): bool => false);
    Feature::purge(['workspace.site_promote']);
    Feature::flushCache();

    [$user, $org] = promoteUserWithOrg();
    $server = promoteReadyServer($user, $org);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_organization_id' => $org->id])
        ->get(route('sites.promote', [$server, $site]))
        ->assertStatus(400);
});

test('promote page renders for vm site', function (): void {
    [$user, $org] = promoteUserWithOrg();
    $server = promoteReadyServer($user, $org);
    // Second billable server → Starter plan headroom for the promote clone.
    promoteReadyServer($user, $org);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_organization_id' => $org->id])
        ->get(route('sites.promote', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Promote to server'));
});

test('start promote dispatches clone job with preview flags', function (): void {
    Queue::fake();

    [$user, $org] = promoteUserWithOrg();
    $server = promoteReadyServer($user, $org);
    $dest = promoteReadyServer($user, $org);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'prod.example.com',
        'is_primary' => true,
    ]);

    session(['current_organization_id' => $org->id]);

    $component = Livewire::actingAs($user)
        ->test(SitePromote::class, ['server' => $server, 'site' => $site]);

    // Abort/error layouts embed nested Livewire (bell, command palette); ensure
    // we actually mounted SitePromote before driving properties.
    expect($component->instance())->toBeInstanceOf(SitePromote::class);

    $component
        ->set('destination_server_id', (string) $dest->id)
        ->set('promote_site_name', 'Prod standby')
        ->set('hostname_mode', 'preview')
        ->call('startPromote')
        ->assertHasNoErrors()
        ->assertRedirect(route('servers.sites', $dest));

    Queue::assertPushed(CloneSiteJob::class, function (CloneSiteJob $job) use ($site, $dest, $user): bool {
        return $job->sourceSiteId === (string) $site->id
            && $job->destinationServerId === (string) $dest->id
            && $job->siteName === 'Prod standby'
            && $job->userId === (string) $user->id
            && $job->previewFirstPromote === true
            && $job->sourceProductionHostname === 'prod.example.com';
    });
});