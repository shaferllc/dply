<?php

declare(strict_types=1);

namespace Tests\Feature\ServerScheduledDeletionTest;
use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\WorkspaceOverview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Notifications\UniversalEventNotification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
test('scheduling removal sets timestamp and keeps server', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'sched-test-server',
    ]);

    $futureDate = now()->addDays(10)->toDateString();

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->call('openRemoveServerModal')
        ->set('removeMode', 'scheduled')
        ->set('scheduledRemovalDate', $futureDate)
        ->set('deleteConfirmName', 'sched-test-server')
        ->call('submitRemoveServer')
        ->assertHasNoErrors();

    $server->refresh();
    expect($server->scheduled_deletion_at)->not->toBeNull();
    expect($server->scheduled_deletion_at->isFuture())->toBeTrue();
    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'server.removal.scheduled',
        'subject_type' => Server::class,
        'subject_id' => $server->id,
        'organization_id' => $org->id,
    ]);
    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'type' => UniversalEventNotification::class,
    ]);
});
test('scheduling removal requires name confirmation', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'exact-name',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->call('openRemoveServerModal')
        ->set('removeMode', 'scheduled')
        ->set('scheduledRemovalDate', now()->addDays(3)->toDateString())
        // Intentionally not setting deleteConfirmName — type-to-confirm
        // applies to every mode now, including scheduled.
        ->call('submitRemoveServer')
        ->assertHasErrors(['deleteConfirmName']);

    $server->refresh();

    expect($server->scheduled_deletion_at)->toBeNull();
});
test('in 30 minutes mode schedules near term removal', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'grace-window-server',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->call('openRemoveServerModal')
        ->set('removeMode', 'in_30')
        ->set('deleteConfirmName', 'grace-window-server')
        ->call('submitRemoveServer')
        ->assertHasNoErrors();

    $server->refresh();
    expect($server->scheduled_deletion_at)->not->toBeNull();

    // ~30 minutes from now, allowing wide slack for test machine drift.
    expect($server->scheduled_deletion_at->between(now()->addMinutes(28), now()->addMinutes(32)))->toBeTrue();
});
test('process scheduled deletions command removes due servers', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $server->update(['scheduled_deletion_at' => now()->subMinute()]);

    $this->artisan(ProcessScheduledServerDeletionsCommand::class)->assertSuccessful();

    $this->assertModelMissing($server);
    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'server.removal.executed',
        'organization_id' => $org->id,
    ]);
    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'type' => UniversalEventNotification::class,
    ]);
});
test('cancel scheduled removal clears timestamp', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $server->update(['scheduled_deletion_at' => now()->addWeek()]);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->call('cancelScheduledServerRemoval');

    expect($server->fresh()->scheduled_deletion_at)->toBeNull();
});
test('server overview quick assign removed placeholder', function () {
    // Sentinel: the inline quick-assign + quick-add UI on the
    // overview was removed; assert the page no longer mentions
    // them by their previous labels. If a future change reintroduces
    // similar inline UI, this test will need to be updated to
    // reflect the new shape.
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'notify-server',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertDontSee('Quick assign')
        ->assertDontSee('Assign server events');
});
test('rerun setup queues fresh setup attempt for existing server', function () {
    Queue::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'setup_status' => Server::SETUP_STATUS_FAILED,
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
            'provision_task_id' => 'old-task-id',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->call('rerunSetup')
        ->assertRedirect(route('servers.journey', $server));

    $server->refresh();

    expect($server->setup_status)->toBe(Server::SETUP_STATUS_PENDING);
    $this->assertArrayNotHasKey('provision_task_id', $server->meta ?? []);

    Queue::assertPushed(WaitForServerSshReadyJob::class, function (WaitForServerSshReadyJob $job) use ($server) {
        return $job->server->is($server);
    });
});
