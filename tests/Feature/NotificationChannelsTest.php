<?php

namespace Tests\Feature;

use App\Livewire\Organizations\NotificationChannels as OrgNotificationChannels;
use App\Livewire\Settings\BulkNotificationAssignments;
use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Livewire\Teams\NotificationChannels as TeamNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_profile_notification_channels(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.notification-channels'))
            ->assertOk();
    }

    public function test_profile_notification_channels_page_shows_current_org_and_team_channels(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['name' => 'Acme']);
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $org->notificationChannels()->create([
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Org alerts',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/ORG'],
        ]);

        $team = Team::query()->create([
            'organization_id' => $org->id,
            'name' => 'Engineering',
            'slug' => 'engineering',
        ]);
        $team->users()->attach($user->id, ['role' => 'member']);
        $team->notificationChannels()->create([
            'type' => NotificationChannel::TYPE_EMAIL,
            'label' => 'Team inbox',
            'config' => ['email' => 'team@example.com'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('profile.notification-channels'))
            ->assertOk()
            ->assertSee('Available beyond your personal channels')
            ->assertSee('Acme')
            ->assertSee('Org alerts')
            ->assertSee('Engineering')
            ->assertSee('Team inbox');
    }

    public function test_user_can_create_a_personal_notification_channel(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileNotificationChannels::class)
            ->set('new_type', NotificationChannel::TYPE_SLACK)
            ->set('new_label', 'Alerts')
            ->set('new_slack_webhook_url', 'https://hooks.slack.com/services/T000/B000/XXXXXXXX')
            ->call('createChannel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_channels', [
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Alerts',
        ]);
    }

    public function test_organization_admin_can_view_org_notification_channels(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->get(route('organizations.notification-channels', $org))
            ->assertOk();
    }

    public function test_organization_member_without_admin_cannot_view_org_notification_channels(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $this->actingAs($user)
            ->get(route('organizations.notification-channels', $org))
            ->assertForbidden();
    }

    public function test_team_member_can_view_team_notification_channels(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        $team = Team::query()->create([
            'organization_id' => $org->id,
            'name' => 'Engineering',
            'slug' => 'engineering',
        ]);
        $team->users()->attach($user->id, ['role' => 'member']);

        $this->actingAs($user)
            ->get(route('teams.notification-channels', [$org, $team]))
            ->assertOk();
    }

    public function test_team_org_admin_can_send_slack_test_notification(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $team = Team::query()->create([
            'organization_id' => $org->id,
            'name' => 'Engineering',
            'slug' => 'engineering',
        ]);
        $team->users()->attach($user->id, ['role' => 'member']);

        $channel = $team->notificationChannels()->create([
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'T',
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/x/y/z',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(TeamNotificationChannels::class, ['organization' => $org, 'team' => $team])
            ->call('sendTest', $channel->id)
            ->assertDispatched('notify', message: __('Test message sent.'), type: 'success');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
    }

    public function test_org_admin_can_create_org_notification_channel(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        Livewire::actingAs($user)
            ->test(OrgNotificationChannels::class, ['organization' => $org])
            ->set('new_type', NotificationChannel::TYPE_WEBHOOK)
            ->set('new_label', 'Ops')
            ->set('new_webhook_url', 'https://example.test/hook')
            ->call('createChannel')
            ->assertHasNoErrors();

        $this->assertSame(1, $org->fresh()->notificationChannels()->count());
    }

    public function test_user_can_create_email_channel_and_send_test(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileNotificationChannels::class)
            ->set('new_type', NotificationChannel::TYPE_EMAIL)
            ->set('new_label', 'Ops inbox')
            ->set('new_email_address', 'ops@example.com')
            ->call('createChannel')
            ->assertHasNoErrors();

        $channel = NotificationChannel::query()->where('owner_type', User::class)->where('owner_id', $user->id)->firstOrFail();

        Livewire::actingAs($user)
            ->test(ProfileNotificationChannels::class)
            ->call('sendTest', $channel->id)
            ->assertDispatched('notify', message: __('Test email sent.'), type: 'success');
    }

    public function test_bulk_assign_notifications_creates_subscription(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $site = Site::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'server_id' => $server->id,
        ]);

        $channel = NotificationChannel::factory()->forUser($user)->create([
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Alerts',
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(BulkNotificationAssignments::class)
            ->set('selected_channel_ids', [(string) $channel->id])
            ->set('selected_event_keys', ['server.ssh_login', 'site.deployments'])
            ->set('selected_server_ids', [(string) $server->id])
            ->set('selected_site_ids', [(string) $site->id])
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => 'server.ssh_login',
        ]);

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Site::class,
            'subscribable_id' => $site->id,
            'event_key' => 'site.deployments',
        ]);
    }

    public function test_bulk_assign_page_renders_when_authenticated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.notification-channels.bulk-assign'))
            ->assertOk()
            ->assertSee('Bulk assign notifications', false);
    }

    public function test_bulk_assign_page_can_preselect_server_from_query_string(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'web-1',
        ]);

        $this->actingAs($user)
            ->get(route('profile.notification-channels.bulk-assign', ['server' => $server->id]))
            ->assertOk()
            ->assertSee('Assigning notifications for server:')
            ->assertSee('web-1');
    }

    public function test_bulk_assign_page_can_quick_add_notification_channel(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(BulkNotificationAssignments::class)
            ->set('quick_new_owner_scope', 'personal')
            ->set('quick_new_type', NotificationChannel::TYPE_SLACK)
            ->set('quick_new_label', 'Ops alerts')
            ->set('quick_new_slack_webhook_url', 'https://hooks.slack.com/services/T/B/X')
            ->call('createQuickNotificationChannel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_channels', [
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Ops alerts',
        ]);
    }
}
