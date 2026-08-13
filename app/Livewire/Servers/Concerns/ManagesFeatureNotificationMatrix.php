<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Servers\WorkspaceNotifications;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\NotificationSubscriptionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The per-feature half of the notification matrix.
 *
 * Every feature workspace tab (Health, Backups, Errors, Firewall, …) carries a
 * Notifications sub-tab, and each grew its own bespoke "Add a channel" form:
 * pick a channel, pick events, submit, then a separate list of what you already
 * added. {@see WorkspaceNotifications} meanwhile routes the *same* subscription
 * rows through the shared channel matrix — one accordion per channel, tick the
 * events, one Save. Two interaction models over one data model, and operators
 * hit both within a click of each other.
 *
 * This trait gives a feature tab the matrix. The only thing a host supplies is
 * which events it owns ({@see featureEventPrefixes()}); everything else —
 * loading current state, grouping by category, reconciling on save — is the
 * same code path the central page already uses, so the two can no longer drift
 * in behaviour, only in copy.
 *
 * Reconciliation is scoped to the feature's own keys: saving the Health tab
 * cannot touch a channel's backup subscriptions, because those keys are never
 * in `managedKeys`. That is what makes it safe for twenty tabs to write to one
 * subscription table.
 *
 * @phpstan-require-extends Component
 *
 * @property Server $server Set in mount() by InteractsWithServerWorkspace.
 */
trait ManagesFeatureNotificationMatrix
{
    use DispatchesToastNotifications;

    /**
     * channel id => list of subscribed event keys, bound by the matrix partial.
     *
     * @var array<string, list<string>>
     */
    public array $channelEventSelections = [];

    /**
     * The exact event keys this tab owns.
     *
     * Explicit rather than a prefix match: most tabs already own a
     * `Server*NotificationKeys::eventKeys()` list, and three of them
     * (insights, shared host, monitor) route keys that share no prefix with
     * their family. Reusing the existing list means the converted tab manages
     * precisely what it managed before.
     *
     * @return list<string>
     */
    abstract protected function featureEventKeys(): array;

    /** Call from the host's mount() (or its tab boot) to seed current state. */
    protected function bootFeatureNotificationMatrix(): void
    {
        $this->channelEventSelections = NotificationSubscriptionMatrix::load(
            Server::class,
            (string) $this->server->id,
            $this->featureManagedEventKeys(),
            $this->assignableMatrixChannels(),
        );
    }

    public function saveFeatureNotificationSubscriptions(): void
    {
        $this->authorize('update', $this->server);

        if (Auth::user()?->currentOrganization()?->userIsDeployer(Auth::user())) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return;
        }

        $channels = $this->assignableMatrixChannels();
        $keys = $this->featureManagedEventKeys();

        $result = NotificationSubscriptionMatrix::save(
            Server::class,
            (string) $this->server->id,
            $keys,
            $channels,
            $this->channelEventSelections,
        );

        // Reload rather than trust the posted array: save() drops unknown keys
        // and channels the actor can't see, so the form must reflect what landed.
        $this->channelEventSelections = NotificationSubscriptionMatrix::load(
            Server::class,
            (string) $this->server->id,
            $keys,
            $channels,
        );

        if (($result['changed'] ?? 0) > 0 && $this->server->organization) {
            audit_log($this->server->organization, Auth::user(), 'server.notifications.subscriptions_updated', $this->server, null, [
                'added' => $result['added'] ?? 0,
                'removed' => $result['removed'] ?? 0,
                'surface' => $this->featureNotificationSurface(),
            ]);
        }

        $this->toastSuccess(__('Subscriptions saved.'));
    }

    /**
     * Grouped events for the matrix, in the same shape the central page uses:
     * list of ['key' =>, 'label' =>, 'events' => [key => label]].
     *
     * @return list<array{key: string, label: string, events: array<string, string>}>
     */
    public function featureEventGroups(): array
    {
        $groups = [];

        foreach ((array) config('notification_events.categories', []) as $key => $category) {
            $events = [];
            foreach ((array) ($category['events'] ?? []) as $eventKey => $label) {
                if ($this->ownsEventKey((string) $eventKey)) {
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
     * Keys this tab owns that no configured category claims.
     *
     * Surfaced so a conversion can't silently drop an event: if a key is routed
     * but unlabelled, it belongs in config/notification_events.php.
     *
     * @return list<string>
     */
    public function unlabelledFeatureEventKeys(): array
    {
        $labelled = [];
        foreach ($this->featureEventGroups() as $group) {
            $labelled = array_merge($labelled, array_keys($group['events']));
        }

        return array_values(array_diff($this->featureManagedEventKeys(), $labelled));
    }

    /** @return list<string> */
    protected function featureManagedEventKeys(): array
    {
        return array_values(array_unique($this->featureEventKeys()));
    }

    /**
     * Re-seed after a channel is created inline.
     *
     * Deliberately NOT an #[On('notification-channel-created')] handler: that
     * event already has a listener on several hosts, and Livewire fatals on two
     * methods claiming the same event. Hosts call this from the handler they
     * already have.
     */
    public function reloadFeatureNotificationMatrix(): void
    {
        $this->bootFeatureNotificationMatrix();
    }

    private function ownsEventKey(string $eventKey): bool
    {
        return in_array($eventKey, $this->featureEventKeys(), true);
    }

    /**
     * Channels the actor may route to — personal, organization and team owned.
     *
     * @return Collection<int, NotificationChannel>
     */
    protected function assignableMatrixChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(
            Auth::user(),
            Auth::user()?->currentOrganization(),
        );
    }

    /** Audit breadcrumb so a change can be traced to the tab it came from. */
    protected function featureNotificationSurface(): string
    {
        return class_basename(static::class);
    }
}
