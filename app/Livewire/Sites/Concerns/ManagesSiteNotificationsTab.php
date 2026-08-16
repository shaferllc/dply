<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
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
            $auditOrg = $this->site->server->organization ?? auth()->user()?->currentOrganization();
            if ($auditOrg) {
                audit_log($auditOrg, auth()->user(), 'site.notifications.subscriptions_updated', $this->site, null, [
                    'added' => $changed['added'],
                    'removed' => $changed['removed'],
                ]);
            }
        }

        $this->dispatch('notify', message: __('Site notification subscriptions saved.'));
    }
}
