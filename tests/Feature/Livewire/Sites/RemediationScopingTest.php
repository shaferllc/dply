<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\RemediationScopingTest;

use App\Modules\Remediations\Jobs\ApplyRemediationJob;
use App\Livewire\Sites\DeploymentDetail;
use App\Livewire\Sites\Errors;
use App\Models\ErrorEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Security regression guard: the inline "Fix this error" actions must only ever
 * act on a row inside the current site's scope. Both surfaces re-fetch the
 * target through a site-scoped query before dispatching {@see ApplyRemediationJob},
 * so passing another tenant's id must be a no-op — never a cross-tenant fix.
 *
 * If a refactor drops the `forSite()` / `where('site_id', …)` scoping, these
 * tests fail because the job dispatches for a foreign id.
 */

/** @return array{0: User, 1: Server, 2: Site} A fully-owned org/server/site. */
function ownedSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    return [$user, $server, $site];
}

/** A second, unrelated tenant's site — the "victim" whose rows must stay untouchable. */
function foreignSite(): array
{
    $other = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($other->id, ['role' => 'owner']);

    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $other->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $other->id,
    ]);

    return [$server, $site];
}

function makeErrorEvent(Server $server, Site $site, string $source): ErrorEvent
{
    return ErrorEvent::query()->create([
        'organization_id' => $site->organization_id,
        'server_id' => $server->id,
        'site_id' => $site->id,
        'source_type' => 'console_action',
        'source_id' => $source,
        'category' => 'deploy',
        // A real catalog code so the *only* thing that can block the fix is scope.
        'remediation_code' => 'disk_full',
        'title' => 'No space left on device',
        'occurred_at' => now(),
    ]);
}

test('applyRemediation dispatches for an in-scope error', function () {
    Queue::fake();
    [$user, $server, $site] = ownedSite();
    $event = makeErrorEvent($server, $site, 'src-own');

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->call('applyRemediation', $event->id, 'show_disk');

    Queue::assertPushed(ApplyRemediationJob::class);
});

test('applyRemediation refuses another tenant’s error id', function () {
    Queue::fake();
    [$user, $server, $site] = ownedSite();
    [$foreignServer, $foreignSite] = foreignSite();
    // An identically-valid event — the ONLY difference is it belongs elsewhere.
    $foreignEvent = makeErrorEvent($foreignServer, $foreignSite, 'src-foreign');

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->call('applyRemediation', $foreignEvent->id, 'show_disk');

    Queue::assertNotPushed(ApplyRemediationJob::class);
});

test('applyDeploymentRemediation refuses another tenant’s deployment id', function () {
    Queue::fake();
    [$user, $server, $site] = ownedSite();
    [, $foreignSite] = foreignSite();

    // A legit own deployment to mount the detail page on.
    $own = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'No space left on device',
    ]);

    // The foreign deployment whose id the attacker tries to drive.
    $foreign = SiteDeployment::query()->create([
        'site_id' => $foreignSite->id,
        'project_id' => $foreignSite->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'No space left on device',
    ]);

    Livewire::actingAs($user)
        ->test(DeploymentDetail::class, ['server' => $server, 'site' => $site, 'deployment' => $own])
        ->call('applyDeploymentRemediation', $foreign->id, 'show_disk');

    Queue::assertNotPushed(ApplyRemediationJob::class);
});
