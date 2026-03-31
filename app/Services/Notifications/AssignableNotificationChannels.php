<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class AssignableNotificationChannels
{
    /**
     * Channels the user may attach to subscriptions (personal, org admin, team manager).
     *
     * @return Collection<int, NotificationChannel>
     */
    public static function forUser(User $user, ?Organization $org): Collection
    {
        $ids = NotificationChannel::query()
            ->where('owner_type', User::class)
            ->where('owner_id', $user->id)
            ->pluck('id');

        if ($org) {
            if ($org->hasAdminAccess($user)) {
                $ids = $ids->merge(
                    NotificationChannel::query()
                        ->where('owner_type', Organization::class)
                        ->where('owner_id', $org->id)
                        ->pluck('id')
                );
            }

            $teamIds = $user->teams()
                ->where('teams.organization_id', $org->id)
                ->pluck('teams.id');

            foreach ($teamIds as $tid) {
                $team = Team::query()->find($tid);
                if ($team && Gate::allows('manageNotificationChannels', $team)) {
                    $ids = $ids->merge(
                        NotificationChannel::query()
                            ->where('owner_type', Team::class)
                            ->where('owner_id', $tid)
                            ->pluck('id')
                    );
                }
            }
        }

        return NotificationChannel::query()
            ->whereIn('id', $ids->unique()->values()->all())
            ->withCount('subscriptions')
            ->orderBy('label')
            ->get();
    }
}
