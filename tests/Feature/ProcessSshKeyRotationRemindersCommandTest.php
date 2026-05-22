<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Notifications\SshKeyRotationDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProcessSshKeyRotationRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_owner_when_review_after_has_passed(): void
    {
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

        $this->assertSame(0, $exit);
        Notification::assertSentTo($owner, SshKeyRotationDueNotification::class);
        $this->assertTrue(Cache::has('ssh_key_rotation_reminder:'.$key->id.':'.now()->toDateString()));
    }

    public function test_skips_keys_with_review_after_in_future(): void
    {
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
    }

    public function test_skips_keys_with_no_review_after(): void
    {
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
    }

    public function test_dedupes_within_same_day(): void
    {
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
    }

    public function test_skips_keys_on_servers_that_are_not_ready(): void
    {
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
    }
}
