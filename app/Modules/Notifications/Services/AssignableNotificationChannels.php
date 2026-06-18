<?php

namespace App\Modules\Notifications\Services;

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

            // Batch the team fetch and pre-attach the organization relation we already
            // have in scope — Team::userCanManageSshKeys checks $this->organization,
            // and without this the gate check lazy-loads the same Organization once
            // per team.
            $teams = $teamIds->isEmpty()
                ? collect()
                : Team::query()->whereIn('id', $teamIds)->get()
                    ->each(fn (Team $team) => $team->setRelation('organization', $org));

            foreach ($teams as $team) {
                if (Gate::allows('manageNotificationChannels', $team)) {
                    $ids = $ids->merge(
                        NotificationChannel::query()
                            ->where('owner_type', Team::class)
                            ->where('owner_id', $team->id)
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
