<?php

namespace App\Modules\Notifications\Services;

use App\Models\ErrorEvent;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Errors\ErrorEventSyncer;
use App\Support\ServerErrorsNotificationKeys;
use App\Support\SiteErrorsNotificationKeys;
use Illuminate\Support\Str;

/**
 * Publishes a notification for a single newly-captured {@see ErrorEvent} — fired
 * from {@see ErrorEventSyncer} as the per-minute sweep promotes
 * a failed ConsoleAction / SiteDeployment into the error stream. This is the entry
 * point the syncer calls; it coordinates BOTH notification scopes for one event.
 *
 * Server roll-up: subject is the {@see Server} the error belongs to (errors with
 * no server_id — pure org-level — can't route to a server subscribable and are
 * skipped). The per-kind title is the config label; the body carries the error's
 * own title and a clipped detail, and "Open" deep-links to the source.
 *
 * Site scope: when the error belongs to a site, {@see SiteErrorsNotificationDispatcher}
 * first notifies the site's subscribers (site.errors.*). The same failure also
 * surfaces in the server roll-up, so the server publish below excludes anything the
 * site dispatch already delivered — both the channels routed by site subscriptions
 * (excludeChannelIds) and the site's in-app stakeholders (excludeRecipientUserIds).
 * Net effect: the site owns its errors, the "watch the whole box" server
 * subscription still works, and a subscriber wired to both is notified exactly once.
 *
 * Mirrors {@see ServerHealthNotificationDispatcher}, but per-event rather than
 * per-posture-transition (errors are discrete, not a rollup).
 */
final class ServerErrorsNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly SiteErrorsNotificationDispatcher $siteDispatcher,
        private readonly ResourceNotificationContextResolver $contextResolver,
    ) {}

    public function notify(ErrorEvent $event, ?User $actor = null): void
    {
        // Site-scoped dispatch first (when the error belongs to a site). The site
        // "owns" its error; capture what that delivery covered so the server
        // roll-up below can dedupe against it.
        $excludeChannelIds = [];
        $excludeRecipientIds = [];
        $site = $event->site;
        if ($site instanceof Site) {
            $this->siteDispatcher->notify($event, $actor);
            $excludeChannelIds = $this->siteSubscriptionChannelIds($event, $site);
            $excludeRecipientIds = $this->contextResolver->resolve($site)['stakeholder_user_ids'];
        }

        $server = $event->server;
        if (! $server instanceof Server) {
            return;
        }

        $kind = ServerErrorsNotificationKeys::kindForCategory((string) $event->category);
        $eventKey = ServerErrorsNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        $errorTitle = trim((string) $event->title);
        if ($errorTitle !== '') {
            $lines[] = $errorTitle;
        }

        $detail = trim((string) $event->detail);
        if ($detail !== '') {
            $lines[] = Str::limit($detail, 500);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            // Prefer the source's own deep link (the failed deploy / settings
            // section) so the alert lands where you can act; fall back to the
            // server's Errors stream.
            url: ($event->link_url ?: null) ?? route('servers.errors', $server, absolute: true),
            metadata: [
                'server_id' => $server->id,
                'error_event_id' => $event->id,
                'category' => (string) $event->category,
                'site_id' => $event->site_id,
                'kind' => $kind,
            ],
            actor: $actor,
            excludeChannelIds: $excludeChannelIds,
            excludeRecipientUserIds: $excludeRecipientIds,
        );
    }

    /**
     * Channels the site-scoped dispatch already routed this error to — the site
     * subscriptions for the SAME kind (so server.errors.deploy_failed is only
     * suppressed by site.errors.deploy_failed, never across kinds). Passed to the
     * server publish as excludeChannelIds so a channel on both surfaces gets one copy.
     *
     * @return list<string>
     */
    private function siteSubscriptionChannelIds(ErrorEvent $event, Site $site): array
    {
        $kind = SiteErrorsNotificationKeys::kindForCategory((string) $event->category);

        return NotificationSubscription::query()
            ->where('subscribable_type', Site::class)
            ->where('subscribable_id', $site->id)
            ->where('event_key', SiteErrorsNotificationKeys::eventKey($kind))
            ->pluck('notification_channel_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.errors.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
