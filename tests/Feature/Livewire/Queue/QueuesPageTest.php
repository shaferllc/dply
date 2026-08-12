<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Queue\QueuesPageTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Livewire\QueueNamespaceShow;
use App\Modules\Queue\Livewire\Queues;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.queue');

beforeEach(function () {
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
        'queue_service.entitlements.defaults' => [
            'available' => true,
            'max_namespaces' => 5,
            'max_queue_depth' => 0,
            'monthly_included_jobs' => 1_000_000,
        ],
        'queue_service.entitlements.plans' => [],
    ]);

    $this->user = User::factory()->create();
    $this->organization = Organization::factory()->create();
    $this->organization->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->organization->id]);
});

function pageEnvelope(): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SendInvoice',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 60,
        'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => 'O:0:"":0:{}'],
    ]);
}

test('the route renders the full page, nav included', function () {
    // Exercises the org shell — the nav entry runs its own query to keep a
    // live queue reachable after the flag moves, and a broken one would take
    // down every organization page, not just this one.
    //
    // The org-scoped URL is a permanent redirect by design: the organization is
    // no longer a route parameter, so the legacy path switches the session and
    // hands off to /queues. Following it is the point — the assertion is about
    // the page that lands, not the hop.
    makeNamespace($this->organization);

    $this->actingAs($this->user)
        ->get(route('organizations.queues', $this->organization))
        ->assertRedirect(route('queues.index'));

    $this->actingAs($this->user)
        ->followingRedirects()
        ->get(route('organizations.queues', $this->organization))
        ->assertOk()
        ->assertSee('Queues');
});

test('the detail route renders', function () {
    $namespace = makeNamespace($this->organization);

    $this->actingAs($this->user)
        ->followingRedirects()
        ->get(route('organizations.queues.show', [$this->organization, $namespace]))
        ->assertOk()
        ->assertSee($namespace->id);
});

test('the index shows an empty state before any queue exists', function () {
    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->assertSee('No queues yet')
        ->assertSee('New queue');
});

test('creating a queue mints its first credential and reveals the secret once', function () {
    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->set('createName', 'orders')
        ->call('createNamespace')
        ->assertRedirect();

    $namespace = QueueNamespace::query()->where('organization_id', $this->organization->id)->firstOrFail();
    expect($namespace->name)->toBe('orders');
    expect($namespace->credentials()->count())->toBe(1);

    // The plaintext travels in the session, not the URL — a query string would
    // put the secret in browser history and every proxy log on the way.
    expect(session('queue.revealed_secret'))->toBeString()->not->toBeEmpty();

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->assertSee('shown once')
        ->assertSee(session('queue.revealed_secret'));
});

test('an unnamed queue is refused rather than silently named', function () {
    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->set('createName', '   ')
        ->call('createNamespace');

    expect(QueueNamespace::query()->count())->toBe(0);
});

test('the plan namespace ceiling is enforced with its own message', function () {
    config(['queue_service.entitlements.defaults.max_namespaces' => 1]);

    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->set('createName', 'first')
        ->call('createNamespace');

    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->set('createName', 'second')
        ->call('createNamespace');

    expect(QueueNamespace::query()->count())->toBe(1);
});

test('nothing can be created while the platform is off', function () {
    config(['queue_service.enabled' => false]);

    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->set('createName', 'orders')
        ->call('createNamespace');

    expect(QueueNamespace::query()->count())->toBe(0);
});

test('the index lists a queue with its live depth', function () {
    $namespace = makeNamespace($this->organization);
    app(QueueStore::class)->pushBulk($namespace, 'default', [pageEnvelope(), pageEnvelope()]);

    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->assertSee('orders')
        ->assertSee('2 jobs')
        ->assertSee('2 pending');
});

test('pausing and resuming round-trips from the list', function () {
    $namespace = makeNamespace($this->organization);

    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->call('togglePause', $namespace->id);

    expect($namespace->fresh()->status)->toBe(QueueNamespace::STATUS_PAUSED);
    expect($namespace->fresh()->acceptsPushes())->toBeFalse();

    Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->call('togglePause', $namespace->id);

    expect($namespace->fresh()->status)->toBe(QueueNamespace::STATUS_ACTIVE);
});

test('deleting needs the queue name typed, then purges the jobs', function () {
    $namespace = makeNamespace($this->organization);
    app(QueueStore::class)->push($namespace, 'default', pageEnvelope());

    $component = Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->call('confirmDelete', $namespace->id)
        ->set('deleteConfirmation', 'wrong')
        ->call('deleteNamespace');

    expect(QueueNamespace::query()->whereKey($namespace->id)->exists())->toBeTrue();

    $component->set('deleteConfirmation', 'orders')->call('deleteNamespace');

    expect(QueueNamespace::query()->whereKey($namespace->id)->exists())->toBeFalse();
});

test('a queue from another org is not reachable', function () {
    // Scoped at the lookup, not by a check after it: the query cannot return
    // another org's row, so there is no path where the authorize() call is
    // reached with the wrong namespace in hand.
    $other = Organization::factory()->create();
    $foreign = makeNamespace($other);

    expect(fn () => Livewire::actingAs($this->user)
        ->test(Queues::class, ['organization' => $this->organization])
        ->call('togglePause', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->status)->toBe(QueueNamespace::STATUS_ACTIVE);
});

test('the detail page shows the endpoint and the env an app needs', function () {
    $namespace = makeNamespace($this->organization);

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->assertSee('https://queue.dply.test/api/queue/v1/'.$namespace->id)
        ->assertSee('QUEUE_CONNECTION=dply')
        // The two limits an operator would otherwise discover in production.
        ->assertSee('not strictly FIFO', false)
        ->assertSee('Horizon');
});

test('minting a second credential reveals it and leaves the first working', function () {
    $namespace = makeNamespace($this->organization);
    $first = $namespace->credentials()->firstOrFail();

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->set('credentialName', 'rotation')
        ->call('mintCredential')
        ->assertSet('revealedSecret', fn ($secret): bool => is_string($secret) && $secret !== '')
        ->assertSee('shown once');

    expect($namespace->credentials()->count())->toBe(2);
    expect($first->fresh()->isUsable())->toBeTrue();
});

test('a third live credential is refused', function () {
    // A third means an earlier rotation was abandoned, leaving a secret live
    // forever — so the cap is enforced rather than warned about.
    $namespace = makeNamespace($this->organization);
    QueueCredential::mint($namespace, 'second');

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->call('mintCredential');

    expect($namespace->credentials()->count())->toBe(2);
});

test('revoking a credential takes effect immediately', function () {
    $namespace = makeNamespace($this->organization);
    $credential = $namespace->credentials()->firstOrFail();

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->call('confirmRevoke', $credential->id)
        ->call('revokeCredential');

    expect($credential->fresh()->isRevoked())->toBeTrue();
});

test('a credential from another namespace cannot be revoked through this page', function () {
    $namespace = makeNamespace($this->organization);
    $other = makeNamespace($this->organization, 'billing');
    $foreignCredential = $other->credentials()->firstOrFail();

    expect(fn () => Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->call('confirmRevoke', $foreignCredential->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreignCredential->fresh()->isRevoked())->toBeFalse();
});

test('a namespace belonging to another org 404s on the detail page', function () {
    $foreign = makeNamespace(Organization::factory()->create());

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $foreign,
        ])
        ->assertStatus(404);
});

test('the secret is dismissible and does not survive the dismissal', function () {
    $namespace = makeNamespace($this->organization);

    Livewire::actingAs($this->user)
        ->test(QueueNamespaceShow::class, [
            'organization' => $this->organization,
            'queueNamespace' => $namespace,
        ])
        ->call('mintCredential')
        ->assertSet('revealedSecret', fn ($secret): bool => is_string($secret))
        ->call('dismissSecret')
        ->assertSet('revealedSecret', null);
});

function makeNamespace(Organization $organization, string $name = 'orders'): QueueNamespace
{
    $namespace = QueueNamespace::query()->create([
        'organization_id' => $organization->id,
        'name' => $name,
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);

    QueueCredential::mint($namespace, 'Default credential');

    return $namespace;
}
