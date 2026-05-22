<?php

declare(strict_types=1);

namespace Tests\Feature\DetectWebserverHealthTransitionsJobTest;
use App\Jobs\DetectWebserverHealthTransitionsJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use App\Notifications\WebserverHealthAlertNotification;
use Illuminate\Support\Facades\Notification;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
}
function snapshot(Server $server, array $webserverHealth): void
{
    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => [
            'cpu_pct' => 5.0,
            'webserver_health' => $webserverHealth,
        ],
    ]);
}
test('fires alert on threshold trip', function () {
    Notification::fake();
    $server = makeServer();

    // Default config threshold for active_connections is gt 5000.
    snapshot($server, [['engine' => 'nginx', 'active_connections' => 6000]]);

    app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

    Notification::assertSentTimes(WebserverHealthAlertNotification::class, 1);
});
test('no alert when within threshold', function () {
    Notification::fake();
    $server = makeServer();
    snapshot($server, [['engine' => 'nginx', 'active_connections' => 10]]);

    app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

    Notification::assertNothingSent();
});
test('no repeat alert while still tripped', function () {
    Notification::fake();
    $server = makeServer();

    // First scrape — trips.
    snapshot($server, [['engine' => 'nginx', 'active_connections' => 6000]]);
    app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

    // Second scrape — still tripped. Should NOT re-fire.
    snapshot($server, [['engine' => 'nginx', 'active_connections' => 7000]]);
    app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

    Notification::assertSentTimes(WebserverHealthAlertNotification::class, 1);
});
test('fires recovery notification on down transition', function () {
    Notification::fake();
    $server = makeServer();

    snapshot($server, [['engine' => 'nginx', 'active_connections' => 6000]]);
    app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

    // Drop below threshold — should fire RECOVERY notification.
    snapshot($server, [['engine' => 'nginx', 'active_connections' => 100]]);
    app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

    Notification::assertSentTimes(WebserverHealthAlertNotification::class, 2);
});
