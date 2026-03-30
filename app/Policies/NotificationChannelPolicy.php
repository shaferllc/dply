<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class NotificationChannelPolicy
{
    public function view(User $user, NotificationChannel $channel): bool
    {
        return Gate::forUser($user)->allows('viewNotificationChannels', $channel->owner);
    }

    public function update(User $user, NotificationChannel $channel): bool
    {
        return Gate::forUser($user)->allows('manageNotificationChannels', $channel->owner);
    }

    public function delete(User $user, NotificationChannel $channel): bool
    {
        return Gate::forUser($user)->allows('manageNotificationChannels', $channel->owner);
    }
}
