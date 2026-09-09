<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Channels\Intercom\IntercomChannel;
use App\Modules\Notifications\Channels\MicrosoftTeams\MicrosoftTeamsChannel;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyChannel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Until Intercom, this module had no provider: dply's own channel system
 * (App\Models\NotificationChannel + NotificationRoutingResolver) dispatches on a
 * type string and needs nothing registered. Intercom is the first destination
 * that ALSO plugs into Laravel's own notification system, so that
 * `via() => ['intercom']` and `toIntercom()` work on any Notification class —
 * which does require a driver registration.
 *
 * The alias names are load-bearing: they are what
 * laravel-notification-channels/{intercom,pagerduty} document, so notifications
 * written against those docs resolve here unchanged.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Notification::extend('intercom', fn ($app) => $app->make(IntercomChannel::class));

        // Both spellings on purpose. The PagerDuty package documents
        // `via() => [PagerDutyChannel::class]` and
        // `Notification::route('PagerDuty', $key)`, while dply's own type key is
        // the lowercase `pagerduty`. Laravel's ChannelManager already resolves
        // the class-name form through the container, so these two cover the
        // string forms; PagerDutyChannel pins the route lookup to one spelling
        // so the aliases cannot diverge.
        Notification::extend('PagerDuty', fn ($app) => $app->make(PagerDutyChannel::class));
        Notification::extend('pagerduty', fn ($app) => $app->make(PagerDutyChannel::class));

        // Same two-spelling reasoning as PagerDuty. `microsoftTeams` is what the
        // package documents (and what Str::studly turns into the
        // routeNotificationForMicrosoftTeams the channel looks up);
        // `microsoft_teams` is dply's own type key.
        Notification::extend('microsoftTeams', fn ($app) => $app->make(MicrosoftTeamsChannel::class));
        Notification::extend('microsoft_teams', fn ($app) => $app->make(MicrosoftTeamsChannel::class));
    }
}
