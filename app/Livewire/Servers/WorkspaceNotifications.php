<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Sites\Settings;
use App\Models\NotificationChannel;
use App\Models\NotificationWebhookDestination;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Support\NotificationSubscriptionMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The server's central "Notifications" workspace — one place to route notification
 * channels to every server.* event, grouped by category. The site analogue is the
 * Settings → Notifications page ({@see Settings}); this is its
 * server-scoped sibling.
 *
 * Two doors, one data model: the per-feature workspace tabs (Errors → Notifications,
 * Backups → Notifications, Certificates → Notifications, …) each manage a slice of
 * the same {@see NotificationSubscription} rows (subscribable = Server). This page is
 * authoritative over all of them at once, so the matrix here and the per-feature
 * tabs always reflect the same subscriptions.
 *
 * The subscription matrix is a cartesian save (each ticked channel × each ticked
 * event); grouping is a display aid on the events column. Dynamic per-systemd-unit
 * keys (server.systemd.u.*) are not config-listed, so this page never touches them —
 * they stay owned by the Services tab.
 *
 * Integration (outbound) webhooks have no per-server scope in the data model
 * (org-level, optionally site-scoped), so they're managed in Organization →
 * Automation; this page surfaces the organization-wide destinations read-only.
 */
#[Layout('layouts.app')]
class WorkspaceNotifications extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;

    /** @var list<string> */
    public const NOTIF_TABS = ['subscriptions', 'webhooks'];

    #[Url(as: 'tab', except: 'subscriptions')]
    public string $notifTab = 'subscriptions';

    /**
     * Per-channel event routing: channel id → list of subscribed event keys. Each
     * channel carries its own events, so different events can go to different
     * channels. Reconciled per shown channel on save (see {@see NotificationSubscriptionMatrix}).
     *
     * @var array<string, list<string>>
     */
    public array $channelEventSelections = [];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->loadServerNotificationPreferences();
    }

    public function setNotificationsTab(string $tab): void
    {
        $this->notifTab = in_array($tab, self::NOTIF_TABS, true) ? $tab : 'subscriptions';
    }

    /**
     * All config-listed server.* event keys — the set this page is authoritative
     * over. Excludes dynamic systemd per-unit keys (not config-listed).
     *
     * @return list<string>
     */
    protected function managedEventKeys(): array
    {
        $keys = [];
        foreach ((array) config('notification_events.categories', []) as $category) {
            foreach ((array) ($category['events'] ?? []) as $eventKey => $label) {
                if (str_starts_with((string) $eventKey, 'server.')) {
                    $keys[] = (string) $eventKey;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * server.* events grouped by category for the matrix's events column.
     *
     * @return list<array{key: string, label: string, events: array<string, string>}>
     */
    protected function eventCategories(): array
    {
        $groups = [];
        foreach ((array) config('notification_events.categories', []) as $key => $category) {
            $events = [];
            foreach ((array) ($category['events'] ?? []) as $eventKey => $label) {
                if (str_starts_with((string) $eventKey, 'server.')) {
                    $events[(string) $eventKey] = (string) $label;
                }
            }
            if ($events !== []) {
                $groups[] = [
                    'key' => (string) $key,
                    'label' => (string) ($category['label'] ?? $key),
                    'events' => $events,
                ];
            }
        }

        return $groups;
    }

    /**
     * After the reusable inline modal ({@see CreatesNotificationChannelInline})
     * creates a channel, refresh the matrix so the new channel shows up as a row.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId = ''): void
    {
        $this->notifTab = 'subscriptions';
        $this->loadServerNotificationPreferences();
    }

    protected function loadServerNotificationPreferences(): void
    {
        $this->channelEventSelections = NotificationSubscriptionMatrix::load(
            Server::class,
            (string) $this->server->id,
            $this->managedEventKeys(),
            $this->assignableChannels(),
        );
    }

    public function saveServerNotificationSubscriptions(): void
    {
        $this->authorize('update', $this->server);

        if (Auth::user()?->currentOrganization()?->userIsDeployer(Auth::user())) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return;
        }

        $result = NotificationSubscriptionMatrix::save(
            Server::class,
            (string) $this->server->id,
            $this->managedEventKeys(),
            $this->assignableChannels(),
            $this->channelEventSelections,
        );

        $this->loadServerNotificationPreferences();

        if ($result['changed'] > 0 && $this->server->organization) {
            audit_log($this->server->organization, Auth::user(), 'server.notifications.subscriptions_updated', $this->server, null, [
                'added' => $result['added'],
                'removed' => $result['removed'],
            ]);
        }

        $this->toastSuccess(__('Server notification subscriptions saved.'));
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    protected function assignableChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * Organization-wide outbound webhook destinations (no per-server scope exists),
     * surfaced read-only here. Managed under Organization → Automation.
     *
     * @return Collection<int, NotificationWebhookDestination>
     */
    protected function organizationWebhookDestinations(): Collection
    {
        if ($this->server->organization_id === null) {
            return new Collection;
        }

        return NotificationWebhookDestination::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereNull('site_id')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-notifications', [
            'assignableNotificationChannels' => $this->assignableChannels(),
            'eventCategories' => $this->eventCategories(),
            'organizationWebhookDestinations' => $this->organizationWebhookDestinations(),
        ]);
    }
}
