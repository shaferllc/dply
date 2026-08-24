<?php

declare(strict_types=1);

namespace Tests\Feature\ServerResizeNotificationsTest;

use App\Enums\ServerProvider;
use App\Jobs\ResizeServerJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ServerResizeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

/**
 * A droplet with two sites on it, owned by an org with an owner and an admin
 * plus a deployer who should NOT be told.
 *
 * @return array{0: User, 1: User, 2: Server}
 */
function orgWithSitesOnDroplet(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $deployer = User::factory()->create();

    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($admin->id, ['role' => 'admin']);
    $org->users()->attach($deployer->id, ['role' => 'deployer']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_ok'],
    ]);

    $server = Server::factory()->ready()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'provider_id' => '12345',
        'size' => 's-1vcpu-2gb',
        'region' => 'nyc1',
    ]);

    Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'shop.example.com']);
    Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'api.example.com']);

    return [$owner, $deployer, $server];
}

function fakeDropletResize(bool $resizeSucceeds = true): void
{
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/12345/actions/*' => Http::response([
            'action' => ['id' => 1, 'status' => 'completed'],
        ]),
        'https://api.digitalocean.com/v2/droplets/12345/actions' => $resizeSucceeds
            ? Http::response(['action' => ['id' => 1, 'status' => 'completed']])
            : Http::response(['message' => 'Droplet already has that size'], 422),
        'https://api.digitalocean.com/v2/droplets/12345' => Http::response([
            'droplet' => [
                'id' => 12345,
                'size_slug' => 's-1vcpu-2gb',
                'vcpus' => 1, 'memory' => 2048, 'disk' => 50,
                'region' => ['slug' => 'nyc1'],
            ],
        ]),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => [[
                'slug' => 's-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'disk' => 50,
                'regions' => ['nyc1'], 'available' => true, 'price_monthly' => 24.0,
            ]],
        ]),
    ]);
}

test('owners and admins are warned before the machine goes down, deployers are not', function (): void {
    Notification::fake();
    fakeDropletResize();
    [$owner, $deployer, $server] = orgWithSitesOnDroplet();

    (new ResizeServerJob($server, 's-2vcpu-4gb', false, $owner->id))
        ->handle(app(\App\Services\Servers\ServerResizeOptions::class), app(\App\Modules\Notifications\Services\ServerResizeNotificationDispatcher::class));

    Notification::assertSentTo($owner, ServerResizeNotification::class);
    Notification::assertNotSentTo($deployer, ServerResizeNotification::class);
});

test('the warning names the sites that are going offline', function (): void {
    Notification::fake();
    fakeDropletResize();
    [$owner, , $server] = orgWithSitesOnDroplet();

    (new ResizeServerJob($server, 's-2vcpu-4gb', false, $owner->id))
        ->handle(app(\App\Services\Servers\ServerResizeOptions::class), app(\App\Modules\Notifications\Services\ServerResizeNotificationDispatcher::class));

    Notification::assertSentTo($owner, ServerResizeNotification::class, function (ServerResizeNotification $n) use ($owner): bool {
        $meta = $n->event->metadata ?? [];
        if (($meta['phase'] ?? null) !== 'started') {
            return false;
        }

        expect($meta['site_count'])->toBe(2)
            ->and($meta['site_names'])->toContain('shop.example.com', 'api.example.com')
            ->and($meta['power_cycle'])->toBeTrue();

        // And the mail actually renders the count.
        $mail = $n->toMail($owner);
        expect(collect($mail->introLines)->implode(' '))->toContain('2 sites');

        return true;
    });
});

test('a started and a completed notification are both sent on a clean run', function (): void {
    Notification::fake();
    fakeDropletResize();
    [$owner, , $server] = orgWithSitesOnDroplet();

    (new ResizeServerJob($server, 's-2vcpu-4gb', false, $owner->id))
        ->handle(app(\App\Services\Servers\ServerResizeOptions::class), app(\App\Modules\Notifications\Services\ServerResizeNotificationDispatcher::class));

    $phases = [];
    Notification::assertSentTo($owner, ServerResizeNotification::class, function (ServerResizeNotification $n) use (&$phases): bool {
        $phases[] = $n->event->metadata['phase'] ?? null;

        return true;
    });

    expect($phases)->toContain('started')->toContain('completed');
});

test('an illegal target fails before anything is touched and notifies the failure', function (): void {
    Notification::fake();
    fakeDropletResize();
    [$owner, , $server] = orgWithSitesOnDroplet();

    (new ResizeServerJob($server, 's-nonexistent-99gb', false, $owner->id))
        ->handle(app(\App\Services\Servers\ServerResizeOptions::class), app(\App\Modules\Notifications\Services\ServerResizeNotificationDispatcher::class));

    Notification::assertSentTo($owner, ServerResizeNotification::class, function (ServerResizeNotification $n): bool {
        return ($n->event->metadata['phase'] ?? null) === 'failed';
    });

    expect($server->fresh()->meta['resize']['state'])->toBe('failed');

    // No power-off was issued for a target that was never legal.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions')
        && ($request->data()['type'] ?? null) === 'power_off');
});
