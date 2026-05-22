<?php

declare(strict_types=1);

namespace Tests\Feature\ProcessSshKeyRotationRemindersCommandTest;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Notifications\SshKeyRotationDueNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('notifies owner when review after has passed', function () {
    Notification::fake();
    Cache::flush();

    $owner = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $owner->id]);
    $key = ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'forge',
        'managed_key_type' => null,
        'managed_key_id' => null,
        'name' => 'Operator key',
        'public_key' => 'ssh-ed25519 AAAA',
        'review_after' => now()->subDay(),
    ]);

    $exit = Artisan::call('dply:ssh-key-rotation-reminders');

    expect($exit)->toBe(0);
    Notification::assertSentTo($owner, SshKeyRotationDueNotification::class);
    expect(Cache::has('ssh_key_rotation_reminder:'.$key->id.':'.now()->toDateString()))->toBeTrue();
});
test('skips keys with review after in future', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $owner->id]);
    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'forge',
        'name' => 'Operator key',
        'public_key' => 'ssh-ed25519 AAAA',
        'review_after' => now()->addWeek(),
    ]);

    Artisan::call('dply:ssh-key-rotation-reminders');

    Notification::assertNothingSent();
});
test('skips keys with no review after', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $owner->id]);
    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'forge',
        'name' => 'Operator key',
        'public_key' => 'ssh-ed25519 AAAA',
        'review_after' => null,
    ]);

    Artisan::call('dply:ssh-key-rotation-reminders');

    Notification::assertNothingSent();
});
test('dedupes within same day', function () {
    Notification::fake();
    Cache::flush();

    $owner = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $owner->id]);
    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'forge',
        'name' => 'Operator key',
        'public_key' => 'ssh-ed25519 AAAA',
        'review_after' => now()->subDay(),
    ]);

    Artisan::call('dply:ssh-key-rotation-reminders');
    Artisan::call('dply:ssh-key-rotation-reminders');

    Notification::assertSentToTimes($owner, SshKeyRotationDueNotification::class, 1);
});
test('skips keys on servers that are not ready', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'status' => Server::STATUS_PENDING,
    ]);
    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'target_linux_user' => 'forge',
        'name' => 'Operator key',
        'public_key' => 'ssh-ed25519 AAAA',
        'review_after' => now()->subDay(),
    ]);

    Artisan::call('dply:ssh-key-rotation-reminders');

    Notification::assertNothingSent();
});
