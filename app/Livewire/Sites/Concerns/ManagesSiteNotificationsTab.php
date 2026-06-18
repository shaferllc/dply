<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Models\NotificationWebhookDestination;
use App\Models\Site;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\NotificationSubscriptionMatrix;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteNotificationsTab
{
    /** Sub-tab on the Notifications section (Subscriptions / Integration webhooks). */
    public string $notifTab = 'subscriptions';

    /**
     * Per-channel event routing for the central matrix: channel id → list of
     * subscribed event keys. Lets different events go to different channels in one
     * place (replaces the old cartesian channels×events selection). Save reconciles
     * each shown channel to its selection and never touches channels not listed
     * here, so it stays in sync with the per-feature Notifications tabs.
     *
     * @var array<string, list<string>>
     */
    public array $channelEventSelections = [];

    public string $site_int_hook_name = '';

    public string $site_int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;

    public string $site_int_hook_url = '';

    public bool $site_int_evt_success = true;

    public bool $site_int_evt_failed = true;

    public bool $site_int_evt_skipped = true;

    public bool $site_int_evt_deploy_started = false;

    public bool $site_int_evt_uptime_down = true;

    public bool $site_int_evt_uptime_recovered = true;

    public bool $site_int_evt_uptime_degraded = true;

    public bool $site_int_evt_ssl_expiring = true;

    public function setNotificationsTab(string $tab): void
    {
        $this->notifTab = in_array($tab, self::NOTIF_TABS, true) ? $tab : 'subscriptions';
    }

    /**
     * After the reusable inline modal ({@see CreatesNotificationChannelInline})
     * creates a channel, refresh the matrix so the new channel appears as a row
     * ready to route — without leaving the page. Jump to the Subscriptions tab so
     * the new channel is visible.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId = ''): void
    {
        if ($this->section === 'notifications') {
            $this->notifTab = 'subscriptions';
            $this->loadSiteNotificationPreferences();
        }
    }

    protected function loadSiteNotificationPreferences(): void
    {
        $this->channelEventSelections = NotificationSubscriptionMatrix::load(
            Site::class,
            (string) $this->site->id,
            self::SITE_NOTIFICATION_EVENT_KEYS,
            AssignableNotificationChannels::forUser(auth()->user(), $this->site->organization),
        );
    }

    public function saveSiteNotificationSubscriptions(): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change notification subscriptions.'));

            return;
        }

        $changed = NotificationSubscriptionMatrix::save(
            Site::class,
            (string) $this->site->id,
            self::SITE_NOTIFICATION_EVENT_KEYS,
            AssignableNotificationChannels::forUser(auth()->user(), auth()->user()?->currentOrganization()),
            $this->channelEventSelections,
        );

        $this->loadSiteNotificationPreferences();

        if ($changed['changed'] > 0) {
            $auditOrg = $this->site->server?->organization ?? auth()->user()?->currentOrganization();
            if ($auditOrg) {
                audit_log($auditOrg, auth()->user(), 'site.notifications.subscriptions_updated', $this->site, null, [
                    'added' => $changed['added'],
                    'removed' => $changed['removed'],
                ]);
            }
        }

        $this->dispatch('notify', message: __('Site notification subscriptions saved.'));
    }

    public function saveSiteIntegrationWebhookDestination(): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage integration webhooks.'));

            return;
        }

        $this->validate([
            'site_int_hook_name' => 'required|string|max:120',
            'site_int_hook_driver' => 'required|string|in:slack,discord,teams',
            'site_int_hook_url' => 'required|string|url|max:2000',
        ]);

        $events = [];
        if ($this->site_int_evt_success) {
            $events[] = 'deploy_success';
        }
        if ($this->site_int_evt_failed) {
            $events[] = 'deploy_failed';
        }
        if ($this->site_int_evt_skipped) {
            $events[] = 'deploy_skipped';
        }
        if ($this->site_int_evt_deploy_started) {
            $events[] = 'deploy_started';
        }
        if ($this->site_int_evt_uptime_down) {
            $events[] = 'uptime_down';
        }
        if ($this->site_int_evt_uptime_recovered) {
            $events[] = 'uptime_recovered';
        }
        if ($this->site_int_evt_uptime_degraded) {
            $events[] = 'uptime_degraded';
        }
        if ($this->site_int_evt_ssl_expiring) {
            $events[] = 'ssl_expiring';
        }

        $created = NotificationWebhookDestination::query()->create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'name' => $this->site_int_hook_name,
            'driver' => $this->site_int_hook_driver,
            'webhook_url' => $this->site_int_hook_url,
            'events' => $events !== [] ? $events : null,
            'enabled' => true,
        ]);

        $org = $this->site->server?->organization ?? auth()->user()?->currentOrganization();
        if ($org) {
            audit_log($org, auth()->user(), 'site.integration_webhook.created', $this->site, null, [
                'destination_id' => (string) $created->id,
                'name' => $this->site_int_hook_name,
                'driver' => $this->site_int_hook_driver,
                'events' => $events,
            ]);
        }

        $this->reset([
            'site_int_hook_name',
            'site_int_hook_url',
        ]);
        $this->site_int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;
        $this->site_int_evt_success = true;
        $this->site_int_evt_failed = true;
        $this->site_int_evt_skipped = true;
        $this->site_int_evt_deploy_started = false;
        $this->site_int_evt_uptime_down = true;
        $this->site_int_evt_uptime_recovered = true;
        $this->site_int_evt_uptime_degraded = true;
        $this->site_int_evt_ssl_expiring = true;

        $this->dispatch('notify', message: __('Webhook destination saved.'));
    }

    public function deleteSiteIntegrationWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage integration webhooks.'));

            return;
        }

        $hook = NotificationWebhookDestination::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->firstOrFail();
        $snapshot = [
            'destination_id' => (string) $hook->id,
            'name' => $hook->name,
            'driver' => $hook->driver,
            'events' => $hook->events,
        ];
        $hook->delete();

        $org = $this->site->server?->organization ?? auth()->user()?->currentOrganization();
        if ($org) {
            audit_log($org, auth()->user(), 'site.integration_webhook.deleted', $this->site, $snapshot, null);
        }

        $this->dispatch('notify', message: __('Webhook destination removed.'));
    }

    public function toggleSiteIntegrationWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage integration webhooks.'));

            return;
        }

        $hook = NotificationWebhookDestination::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->firstOrFail();
        $hook->update(['enabled' => ! $hook->enabled]);

        $this->dispatch('notify', message: __('Webhook destination updated.'));
    }
}
