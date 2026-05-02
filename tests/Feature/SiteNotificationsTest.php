<?php

namespace Tests\Feature;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationWebhookDestination;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SiteNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_legacy_webhooks_settings_url_redirects_to_notifications(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'webhooks']))
            ->assertRedirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'notifications',
            ], false));
    }

    public function test_site_settings_mount_redirects_section_webhooks_to_notifications(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'webhooks'])
            ->assertRedirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'notifications',
            ], false));
    }

    public function test_site_notifications_section_saves_channel_subscriptions(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $channel = NotificationChannel::factory()->forUser($user)->create();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'notifications'])
            ->set('site_notification_channel_ids', [(string) $channel->id])
            ->set('site_notification_event_keys', ['site.deployments', 'site.deployment_started'])
            ->call('saveSiteNotificationSubscriptions')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Site::class,
            'subscribable_id' => $site->id,
            'event_key' => 'site.deployments',
        ]);
        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Site::class,
            'subscribable_id' => $site->id,
            'event_key' => 'site.deployment_started',
        ]);
    }

    public function test_site_notifications_section_creates_site_scoped_integration_webhook(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'notifications'])
            ->set('site_int_hook_name', 'CI hook')
            ->set('site_int_hook_driver', NotificationWebhookDestination::DRIVER_SLACK)
            ->set('site_int_hook_url', 'https://hooks.example.test/incoming')
            ->set('site_int_evt_deploy_started', true)
            ->call('saveSiteIntegrationWebhookDestination')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_webhook_destinations', [
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'name' => 'CI hook',
            'driver' => NotificationWebhookDestination::DRIVER_SLACK,
        ]);

        $hook = NotificationWebhookDestination::query()
            ->where('site_id', $site->id)
            ->firstOrFail();
        $this->assertContains('deploy_started', $hook->events ?? []);
    }

    public function test_uptime_monitor_check_publishes_down_then_recovered(): void
    {
        // Monitor GETs must not share the Http::fake sequence with outbound webhook POSTs from notification routing.
        $monitorResponses = [
            [503, ''],
            [503, ''],
            [200, ''],
        ];
        $monitorIndex = 0;
        Http::fake(function (Request $request) use (&$monitorIndex, $monitorResponses) {
            $url = $request->url();
            if (str_contains($url, 'uptime.example.test')) {
                [$code, $body] = $monitorResponses[$monitorIndex] ?? [200, ''];
                $monitorIndex++;

                return Http::response($body, $code);
            }

            return Http::response('ok', 200);
        });

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'uptime.example.test',
            'is_primary' => true,
        ]);

        $monitor = SiteUptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'label' => 'Homepage',
            'last_ok' => null,
        ]);

        config(['site_uptime.enabled' => true]);
        config(['site_uptime.notify_on_transitions' => true]);

        (new RunSiteUptimeMonitorCheckJob((string) $monitor->id))->handle(
            app(SiteUptimeCheckUrlResolver::class),
            app(NotificationPublisher::class),
        );

        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'site.uptime',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
        ]);

        $downMeta = NotificationEvent::query()
            ->where('event_key', 'site.uptime')
            ->latest()
            ->first()
            ?->metadata;
        $this->assertIsArray($downMeta);
        $this->assertSame('down', $downMeta['state'] ?? null);

        (new RunSiteUptimeMonitorCheckJob((string) $monitor->id))->handle(
            app(SiteUptimeCheckUrlResolver::class),
            app(NotificationPublisher::class),
        );

        $uptimeEvents = NotificationEvent::query()
            ->where('event_key', 'site.uptime')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $uptimeEvents);
        $this->assertSame('down', $uptimeEvents[0]->metadata['state'] ?? null);
        $this->assertSame('recovered', $uptimeEvents[1]->metadata['state'] ?? null);
    }
}
