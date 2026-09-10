<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Queue\FleetPanelTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\Queue\Livewire\FleetPanel;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.queue');

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $this->namespace = QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
});

function panel()
{
    return Livewire::actingAs(test()->user)
        ->test(FleetPanel::class, ['queueNamespace' => test()->namespace]);
}

function makeFleet(array $attributes = []): ManagedQueueFleet
{
    return ManagedQueueFleet::query()->create(array_merge([
        'namespace_id' => test()->namespace->id,
        'organization_id' => test()->namespace->organization_id,
        'queue' => 'default',
        'class' => ManagedQueueFleet::CLASS_FLEX,
        'status' => ManagedQueueFleet::STATUS_ACTIVE,
        'memory_mib' => 256,
        'min_workers' => 0,
        'max_workers' => 3,
        'meta' => ['image' => 'registry.dply.test/app:latest'],
    ], $attributes));
}

test('a fleet can be created with a class, a size and a range', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')
        ->set('queue', 'invoices')
        ->set('class', ManagedQueueFleet::CLASS_FLEX)
        ->set('memory_mib', 512)
        ->set('min_workers', 0)
        ->set('max_workers', 5)
        ->call('create')
        ->assertHasNoErrors();

    $fleet = ManagedQueueFleet::query()->where('queue', 'invoices')->first();

    expect($fleet)->not->toBeNull()
        ->and($fleet->memory_mib)->toBe(512)
        ->and($fleet->max_workers)->toBe(5);
});

/** A pro fleet that cold-starts is the one thing the class promises not to do. */
test('a pro fleet is forced to a floor of at least one worker', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')
        ->set('queue', 'ledger')
        ->set('class', ManagedQueueFleet::CLASS_PRO)
        ->set('min_workers', 0)
        ->set('max_workers', 4)
        ->call('create');

    expect(ManagedQueueFleet::query()->where('queue', 'ledger')->first()->min_workers)->toBe(1);
});

/** Two autoscalers on one signal would fight; say so rather than hit the index. */
test('a second fleet on the same queue is refused with an explanation', function () {
    makeFleet(['queue' => 'invoices']);

    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')
        ->set('queue', 'invoices')
        ->call('create')
        ->assertHasErrors('queue');

    expect(ManagedQueueFleet::query()->where('queue', 'invoices')->count())->toBe(1);
});

test('queue names are restricted to what can live in a URL path', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')
        ->set('queue', 'not a queue!')
        ->call('create')
        ->assertHasErrors('queue');
});

test('flex is capped at 2 GiB and pro at 8 GiB', function () {
    panel()->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')->set('class', 'flex')->set('queue', 'a')->set('memory_mib', 4096)
        ->call('create')->assertHasErrors('memory_mib');

    panel()->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')->set('class', 'pro')->set('queue', 'b')->set('memory_mib', 4096)
        ->call('create')->assertHasNoErrors();
});

test('the maximum cannot be below the minimum', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:latest')
        ->set('queue', 'c')
        ->set('min_workers', 5)
        ->set('max_workers', 2)
        ->call('create')
        ->assertHasErrors('max_workers');
});

test('a fleet can be resized', function () {
    $fleet = makeFleet();

    panel()
        ->call('edit', $fleet->id)
        ->set('memory_mib', 1024)
        ->set('max_workers', 8)
        ->call('save')
        ->assertHasNoErrors();

    expect($fleet->fresh()->memory_mib)->toBe(1024)
        ->and($fleet->fresh()->max_workers)->toBe(8);
});

test('pausing and resuming flips the fleet status', function () {
    $fleet = makeFleet();

    panel()->call('togglePause', $fleet->id);
    expect($fleet->fresh()->status)->toBe(ManagedQueueFleet::STATUS_PAUSED);

    panel()->call('togglePause', $fleet->id);
    expect($fleet->fresh()->status)->toBe(ManagedQueueFleet::STATUS_ACTIVE);
});

/** Deleting a fleet must not delete an invoice. */
test('deleting a fleet keeps the worker rows it was billed from', function () {
    $fleet = makeFleet();

    ManagedQueueWorker::query()->create([
        'fleet_id' => $fleet->id,
        'runtime' => 'fake',
        'state' => ManagedQueueWorker::STATE_STOPPED,
        'memory_mib' => 256,
        'started_at' => now()->subMinute(),
        'stopped_at' => now(),
        'billed_seconds' => 60,
    ]);

    panel()->call('delete', $fleet->id);

    expect(ManagedQueueFleet::query()->count())->toBe(0)
        ->and(ManagedQueueWorker::query()->count())->toBe(1);
});

/** Running vs desired is the only visible sign a substrate is refusing work. */
test('the panel shows running workers against the count the autoscaler wanted', function () {
    $fleet = makeFleet(['min_workers' => 0, 'max_workers' => 4]);
    $fleet->forceFill(['desired_workers' => 3])->save();

    ManagedQueueWorker::query()->create([
        'fleet_id' => $fleet->id,
        'runtime' => 'fake',
        'state' => ManagedQueueWorker::STATE_RUNNING,
        'memory_mib' => 256,
        'started_at' => now(),
    ]);

    panel()->assertSee('1 / 3');
});

/** "My fleet is broken" vs "this deployment has no runtime configured". */
test('the panel says when no worker runtime is configured', function () {
    config(['queue_service.fleets.runtime' => 'fake']);

    panel()->assertSee('No worker runtime is configured');

    config(['queue_service.fleets.runtime' => 'docker']);

    panel()->assertDontSee('No worker runtime is configured');
});

test('a fleet with no image says nothing will start', function () {
    makeFleet(['meta' => []]);

    panel()->assertSee('No worker image set');
});

/**
 * The gap this whole slice closes: before the image was a form field, nothing
 * outside the tests ever wrote it, so `FleetReconciler::scaleUp()` logged
 * `queue.fleet.no_image` and started zero workers on every tick — while the
 * panel reported "Workers start when jobs arrive".
 */
test('a fleet cannot be created without an image', function () {
    panel()
        ->call('startCreating')
        ->set('image', '')
        ->set('queue', 'invoices')
        ->call('create')
        ->assertHasErrors('image');

    expect(ManagedQueueFleet::query()->where('queue', 'invoices')->exists())->toBeFalse();
});

test('an image that could break out of the pull command is refused', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'app:latest; rm -rf /')
        ->set('queue', 'invoices')
        ->call('create')
        ->assertHasErrors('image');
});

test('the image and registry user are stored on the fleet', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:v2')
        ->set('registry_username', 'acme-bot')
        ->set('registry_password', 'ghp_secret')
        ->set('queue', 'invoices')
        ->call('create')
        ->assertHasNoErrors();

    $fleet = ManagedQueueFleet::query()->where('queue', 'invoices')->firstOrFail();

    expect($fleet->image)->toBe('ghcr.io/acme/app:v2')
        ->and($fleet->registry_username)->toBe('acme-bot')
        ->and($fleet->registry_password)->toBe('ghp_secret');
});

test('the registry token is encrypted at rest', function () {
    panel()
        ->call('startCreating')
        ->set('image', 'ghcr.io/acme/app:v2')
        ->set('registry_username', 'acme-bot')
        ->set('registry_password', 'ghp_secret')
        ->set('queue', 'invoices')
        ->call('create');

    $raw = (string) DB::table('dply_queue_fleets')->where('queue', 'invoices')->value('registry_password');

    expect($raw)->not->toBe('ghp_secret')
        ->and($raw)->not->toContain('ghp_secret');
});

test('editing never round-trips the stored token through the form', function () {
    $fleet = makeFleet(['registry_username' => 'acme-bot', 'registry_password' => 'ghp_secret']);

    panel()->call('edit', $fleet->id)->assertSet('registry_password', '');
});

test('saving with a blank token keeps the stored one', function () {
    $fleet = makeFleet(['registry_username' => 'acme-bot', 'registry_password' => 'ghp_secret']);

    panel()
        ->call('edit', $fleet->id)
        ->set('image', 'ghcr.io/acme/app:v3')
        ->set('registry_password', '')
        ->call('save')
        ->assertHasNoErrors();

    $fleet->refresh();

    expect($fleet->image)->toBe('ghcr.io/acme/app:v3')
        ->and($fleet->registry_password)->toBe('ghp_secret');
});

test('saving with a new token replaces the stored one', function () {
    $fleet = makeFleet(['registry_username' => 'acme-bot', 'registry_password' => 'ghp_secret']);

    panel()
        ->call('edit', $fleet->id)
        ->set('registry_password', 'ghp_rotated')
        ->call('save')
        ->assertHasNoErrors();

    expect($fleet->refresh()->registry_password)->toBe('ghp_rotated');
});
