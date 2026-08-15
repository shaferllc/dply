<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\Intercom;

use App\Modules\Notifications\Channels\Intercom\Exceptions\MessageIsNotCompleteException;
use App\Modules\Notifications\Channels\Intercom\Exceptions\RequestException;
use App\Modules\Notifications\Services\IntercomClient;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification driver for Intercom, registered as `intercom` by
 * NotificationsServiceProvider.
 *
 * Control flow mirrors laravel-notification-channels/intercom exactly: ask the
 * notification for its IntercomMessage, fall back to the notifiable's
 * routeNotificationFor('intercom') when the message named no recipient, then
 * refuse to send an incomplete message rather than let Intercom 400.
 *
 * The one behavioural difference is credential resolution. Upstream has a single
 * app-wide IntercomClient injected by its service provider; here the token can
 * ride on the message (from a NotificationChannel's encrypted config) so each
 * org reaches its own workspace, falling back to services.intercom.token.
 *
 * @see https://laravel-notification-channels.com/intercom/
 */
class IntercomChannel
{
    /**
     * @throws MessageIsNotCompleteException When the message has no body/from/to
     * @throws RequestException When Intercom answers with a non-2xx
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toIntercom')) {
            return;
        }

        /** @var IntercomMessage $message */
        $message = $notification->toIntercom($notifiable);

        if (! $message->toIsGiven()) {
            $to = $this->routeFor($notifiable, $notification);

            // `false` is upstream's sentinel for "this notifiable has no Intercom
            // route". Treated as a silent skip, not an error: most notifiables
            // will never have an Intercom channel configured, and the mail leg
            // of the same notification has already gone out.
            if ($to === false || $to === null) {
                return;
            }

            $message->to($to);
        }

        if (! $message->isValid()) {
            throw new MessageIsNotCompleteException(
                $message,
                'The message is not valid. Please check that you have filled required params'
            );
        }

        $result = IntercomClient::make($message->getToken(), $message->getRegion())
            ->postMessage($message->toArray());

        if (! $result['ok']) {
            throw new RequestException($result['error'], $result['status']);
        }
    }

    /**
     * @return array<string, mixed>|false|null
     */
    private function routeFor(mixed $notifiable, Notification $notification): array|false|null
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            return false;
        }

        $route = $notifiable->routeNotificationFor('intercom', $notification);

        return is_array($route) ? $route : ($route === false ? false : null);
    }
}
