<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Modules\Realtime\Actions\UpdateRealtimeApp;
use App\Modules\Realtime\Jobs\ProvisionRealtimeAppJob;
use App\Modules\Realtime\Livewire\RealtimeAppShow;
use App\Modules\Realtime\Models\RealtimeApp;
use App\Modules\Realtime\Services\RealtimeBackend;
use App\Modules\Realtime\Services\RealtimePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Force the cache-backed fake relay so anything not explicitly bound below
    // still never reaches Cloudflare.
    config()->set('realtime.fake.enabled', true);
    config()->set('realtime.tiers', [
        'starter' => ['label' => 'Starter', 'max_connections' => 5000, 'price_cents' => 1500],
        'growth' => ['label' => 'Growth', 'max_connections' => 25000, 'price_cents' => 4900],
        'scale' => ['label' => 'Scale', 'max_connections' => 100000, 'price_cents' => 14900],
    ]);
    config()->set('realtime.default_tier', 'starter');
});

/**
 * A recording stand-in for the relay, so the tests can assert on the ORDER of
 * relay calls — which is the whole correctness question for rotation.
 */
function fakeRelay(): object
{
    $backend = new class implements RealtimeBackend
    {
        /** @var list<array{op: string, key: string, enabled: bool}> */
        public array $calls = [];

        public function providerKey(): string
        {
            return 'fake';
        }

        public function provision(RealtimeApp $app): void
        {
            $this->calls[] = ['op' => 'provision', 'key' => (string) $app->app_key, 'enabled' => $app->kvRecord()['enabled']];
        }

        public function deprovision(RealtimeApp $app): void
        {
            $this->calls[] = ['op' => 'deprovision', 'key' => (string) $app->app_key, 'enabled' => false];
        }

        public function fetchPeakConnections(RealtimeApp $app): ?int
        {
            return 0;
        }

        public function fetchStats(RealtimeApp $app): ?array
        {
            return ['connections' => 3, 'peakConnections' => 9];
        }

        public function resetPeakConnections(RealtimeApp $app): void
        {
            $this->calls[] = ['op' => 'reset', 'key' => (string) $app->app_key, 'enabled' => true];
        }
    };

    app()->instance(RealtimeBackend::class, $backend);

    return $backend;
}

/** @return array{0: User, 1: RealtimeApp} */
function realtimeOwner(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $app = RealtimeApp::factory()->for($org)->create([
        'status' => RealtimeApp::STATUS_ACTIVE,
        'tier' => 'starter',
    ]);

    return [$user, $app];
}

it('deletes the old key pointer before issuing a new one', function () {
    Queue::fake();
    $relay = fakeRelay();
    [, $app] = realtimeOwner();
    $originalKey = $app->app_key;

    (new UpdateRealtimeApp)->rotateCredentials($app);

    // The relay resolves connections by key. If the OLD `key:` entry is not
    // deleted first, the revoked key keeps authorising every existing client
    // forever — the rotation would be cosmetic.
    expect($relay->calls)->toHaveCount(1);
    expect($relay->calls[0]['op'])->toBe('deprovision');
    expect($relay->calls[0]['key'])->toBe($originalKey);

    $app->refresh();
    expect($app->app_key)->not->toBe($originalKey);
    expect($app->status)->toBe(RealtimeApp::STATUS_PROVISIONING);
    Queue::assertPushed(ProvisionRealtimeAppJob::class);
});

it('does not rotate at all when the relay cannot be reached', function () {
    Queue::fake();
    app()->instance(RealtimeBackend::class, new class implements RealtimeBackend
    {
        public function providerKey(): string
        {
            return 'broken';
        }

        public function provision(RealtimeApp $app): void {}

        public function deprovision(RealtimeApp $app): void
        {
            throw new RuntimeException('relay unreachable');
        }

        public function fetchPeakConnections(RealtimeApp $app): ?int
        {
            return null;
        }

        public function fetchStats(RealtimeApp $app): ?array
        {
            return null;
        }

        public function resetPeakConnections(RealtimeApp $app): void {}
    });

    [, $app] = realtimeOwner();
    $originalKey = $app->app_key;

    expect(fn () => (new UpdateRealtimeApp)->rotateCredentials($app))->toThrow(RuntimeException::class);

    // Half-rotating would leave the row holding credentials the relay has never
    // seen, which locks every client out with no way back.
    expect($app->fresh()->app_key)->toBe($originalKey);
    Queue::assertNotPushed(ProvisionRealtimeAppJob::class);
});

it('publishes a disabled record when pausing so the relay actually refuses clients', function () {
    $relay = fakeRelay();
    [, $app] = realtimeOwner();

    (new UpdateRealtimeApp)->pause($app);

    // A status change alone would leave the relay happily accepting
    // connections — `enabled` lives in the published record.
    expect($relay->calls)->toHaveCount(1);
    expect($relay->calls[0]['op'])->toBe('provision');
    expect($relay->calls[0]['enabled'])->toBeFalse();
    expect($app->fresh()->status)->toBe(RealtimeApp::STATUS_PAUSED);
});

it('takes a paused app off the bill', function () {
    fakeRelay();
    [, $app] = realtimeOwner();

    expect($app->isBillable())->toBeTrue();

    (new UpdateRealtimeApp)->pause($app);

    expect($app->fresh()->isBillable())->toBeFalse();
});

it('renames without touching the relay', function () {
    $relay = fakeRelay();
    [, $app] = realtimeOwner();

    (new UpdateRealtimeApp)->rename($app, '  Production relay  ');

    expect($app->fresh()->name)->toBe('Production relay');
    // The relay keys off the ULID and public key; a label change moves neither.
    expect($relay->calls)->toBeEmpty();
});

it('refuses to rename an app to nothing', function () {
    fakeRelay();
    [, $app] = realtimeOwner();

    expect(fn () => (new UpdateRealtimeApp)->rename($app, '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('collects connection samples as the page polls', function () {
    fakeRelay();
    [$user, $app] = realtimeOwner();

    $component = Livewire::actingAs($user)
        ->test(RealtimeAppShow::class, ['realtimeApp' => $app])
        ->call('pollStats')
        ->call('pollStats');

    $component->assertSet('liveConnections', 3);
    expect($component->get('samples'))->toHaveCount(2);
    expect($component->get('samples')[0]['connections'])->toBe(3);
});

it('caps the sample buffer so a page left open does not grow forever', function () {
    fakeRelay();
    [$user, $app] = realtimeOwner();

    $component = Livewire::actingAs($user)->test(RealtimeAppShow::class, ['realtimeApp' => $app]);
    for ($i = 0; $i < 25; $i++) {
        $component->call('pollStats');
    }

    expect($component->get('samples'))->toHaveCount(20);
});

it('reports a publish that reached nobody as its own outcome', function () {
    fakeRelay();
    [$user, $app] = realtimeOwner();

    // The relay answers 200 with delivered: 0 when the channel has no
    // subscribers. That is a successful publish, not an error, and the operator
    // needs to be able to tell those apart.
    Http::fake([$app->publishEndpoint() => Http::response(['ok' => true, 'channels' => 1, 'delivered' => 0])]);

    Livewire::actingAs($user)
        ->test(RealtimeAppShow::class, ['realtimeApp' => $app])
        ->call('publishDemoEvent')
        ->assertDispatched('notify', fn (string $event, array $params): bool => ($params['type'] ?? '') === 'warning'
            && str_contains((string) ($params['message'] ?? ''), 'nothing was subscribed'));
});

it('never sends the app secret anywhere but the relay', function () {
    fakeRelay();
    [, $app] = realtimeOwner();

    Http::fake([$app->publishEndpoint() => Http::response(['ok' => true, 'channels' => 1, 'delivered' => 1])]);

    (new RealtimePublisher)->publish($app, 'demo', 'demo-event', ['message' => 'hi']);

    Http::assertSent(function ($request) use ($app): bool {
        // Header auth, and the secret must never end up in the URL where it
        // would be logged by every proxy in between.
        return $request->url() === $app->publishEndpoint()
            && $request->header('X-Dply-Secret')[0] === $app->app_secret
            && ! str_contains($request->url(), $app->app_secret);
    });
});

it('refuses to publish to an app that is not active', function () {
    fakeRelay();
    [, $app] = realtimeOwner();
    $app->forceFill(['status' => RealtimeApp::STATUS_PAUSED])->save();

    expect(fn () => (new RealtimePublisher)->publish($app, 'demo', 'e', []))
        ->toThrow(RuntimeException::class);
});
