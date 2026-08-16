<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\NotificationChannel;
use App\Modules\Notifications\Channels\Intercom\IntercomChannel;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplies the `to` object for Laravel's `intercom` notification driver.
 *
 * Laravel resolves `routeNotificationFor('intercom')` to this method by name, so
 * any notifiable using this trait can be reached through
 * {@see IntercomChannel} without the
 * notification naming a recipient itself.
 *
 * Returning `false` — not null — when there is no Intercom channel is the
 * sentinel laravel-notification-channels/intercom uses for "this notifiable has
 * no Intercom route", and the channel treats it as a silent skip.
 *
 * @phpstan-require-extends Model
 */
trait RoutesIntercomNotifications
{
    /**
     * Laravel passes the notification instance in; this route does not vary by
     * notification, so it is accepted and ignored.
     *
     * @return array{type: string, id?: string, email?: string}|false
     */
    public function routeNotificationForIntercom(mixed $notification = null): array|false
    {
        $channel = $this->notificationChannels()
            ->where('type', NotificationChannel::TYPE_INTERCOM)
            ->first();

        if (! $channel instanceof NotificationChannel) {
            return false;
        }

        $recipient = (string) ($channel->config['recipient'] ?? '');
        if ($recipient === '') {
            return false;
        }

        return match ($channel->config['recipient_type'] ?? NotificationChannel::INTERCOM_TO_USER_EMAIL) {
            NotificationChannel::INTERCOM_TO_USER_ID => ['type' => 'user', 'id' => $recipient],
            NotificationChannel::INTERCOM_TO_CONTACT_ID => ['type' => 'contact', 'id' => $recipient],
            NotificationChannel::INTERCOM_TO_EMAIL => ['type' => 'email', 'email' => $recipient],
            default => ['type' => 'user', 'email' => $recipient],
        };
    }
}
