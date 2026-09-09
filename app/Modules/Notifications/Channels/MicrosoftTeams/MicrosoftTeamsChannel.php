<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\MicrosoftTeams;

use App\Modules\Notifications\Channels\MicrosoftTeams\Exceptions\CouldNotSendNotification;
use App\Modules\Notifications\Services\MicrosoftTeamsClient;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification driver for Microsoft Teams, registered as
 * `microsoftTeams` (and `microsoft_teams`) by NotificationsServiceProvider.
 *
 * Resolution order for the destination follows the package convention: a URL
 * named on the message wins, otherwise the notifiable's
 * routeNotificationForMicrosoftTeams(). No URL is a silent skip, not an error —
 * most notifiables will never have Teams configured.
 */
class MicrosoftTeamsChannel
{
    /**
     * @throws CouldNotSendNotification When Teams/Power Automate rejects the card
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toMicrosoftTeams')) {
            return;
        }

        /** @var MicrosoftTeamsMessage $message */
        $message = $notification->toMicrosoftTeams($notifiable);

        $url = $message->getWebhookUrl() ?? $this->routeFor($notifiable, $notification);

        if ($url === null || $url === '') {
            return;
        }

        if (! $message->isValid()) {
            throw CouldNotSendNotification::incompleteMessage(
                'A Teams card needs at least a title or some content.'
            );
        }

        $result = (new MicrosoftTeamsClient)->send($url, $message->toArray());

        if (! $result['ok']) {
            throw CouldNotSendNotification::serviceRejected(
                MicrosoftTeamsClient::describeError($result['error']),
                $result['status']
            );
        }
    }

    private function routeFor(mixed $notifiable, Notification $notification): ?string
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('microsoftTeams', $notification);

        return is_string($route) && $route !== '' ? $route : null;
    }
}
