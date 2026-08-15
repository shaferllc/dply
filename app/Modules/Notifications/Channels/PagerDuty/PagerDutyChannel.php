<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\PagerDuty;

use App\Modules\Notifications\Channels\PagerDuty\Exceptions\ApiError;
use App\Modules\Notifications\Channels\PagerDuty\Exceptions\CouldNotSendNotification;
use App\Modules\Notifications\Services\PagerDutyClient;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification driver for PagerDuty, registered by
 * NotificationsServiceProvider under both `PagerDuty` and `pagerduty`.
 *
 * Control flow mirrors laravel-notification-channels/pagerduty: resolve the
 * routing key from the notifiable, bail silently when there isn't one, ask the
 * notification for its PagerDutyMessage, then stamp the key on it.
 *
 * The route lookup is hard-coded to the string `PagerDuty` (matching upstream)
 * rather than following whichever alias appeared in via(). Laravel derives the
 * route method name with Str::studly, so `pagerduty` would look for
 * routeNotificationForPagerduty() and `PagerDuty` for
 * routeNotificationForPagerDuty() — two spellings, one of which silently finds
 * nothing. Pinning it here means both aliases resolve the same route.
 *
 * @see https://laravel-notification-channels.com/pagerduty/
 */
class PagerDutyChannel
{
    /**
     * @throws CouldNotSendNotification When the transport fails or the message is incomplete
     * @throws ApiError When PagerDuty answers with a non-2xx
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPagerDuty')) {
            return;
        }

        $routingKey = $this->routingKeyFor($notifiable);

        /** @var PagerDutyMessage $message */
        $message = $notification->toPagerDuty($notifiable);

        // A message may carry its own key (the docs' routeNotificationForPagerDuty
        // returning a literal), in which case the notifiable doesn't need one.
        if ($routingKey !== null && $routingKey !== '') {
            $message->setRoutingKey($routingKey);
        }

        if ($message->getRoutingKey() === null) {
            return;
        }

        if (! $message->isValid()) {
            throw CouldNotSendNotification::incompleteMessage(
                $message->eventAction() === PagerDutyMessage::EVENT_TRIGGER
                    ? 'A PagerDuty trigger needs a summary, a source and a severity.'
                    : 'A PagerDuty '.$message->eventAction().' needs the dedup key of the incident to act on.'
            );
        }

        $result = PagerDutyClient::make($message->getRegion())->enqueue($message->toArray());

        if (! $result['ok']) {
            throw match ($result['status']) {
                400 => ApiError::serviceBadRequest($result['error']),
                429 => ApiError::rateLimit(),
                default => ApiError::unknownError($result['status'], $result['error']),
            };
        }
    }

    private function routingKeyFor(mixed $notifiable): ?string
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('PagerDuty');

        return is_string($route) && $route !== '' ? $route : null;
    }
}
