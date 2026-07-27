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
     * Request-scoped memo — mount + render (and sibling notification tabs)
     * often ask for the same assignable set in one request.
     *
     * @var array<string, Collection<int, NotificationChannel>>
     */
    private static array $memo = [];

    /**
     * Channels the user may attach to subscriptions (personal, org admin, team manager).
     *
     * @return Collection<int, NotificationChannel>
     */
    public static function forUser(User $user, ?Organization $org): Collection
    {
        $key = (string) $user->id.'|'.(string) ($org?->id ?? '');
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

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

            $manageableTeamIds = [];
            foreach ($teams as $team) {
                if (Gate::allows('manageNotificationChannels', $team)) {
                    $manageableTeamIds[] = $team->id;
                }
            }

            if ($manageableTeamIds !== []) {
                $ids = $ids->merge(
                    NotificationChannel::query()
                        ->where('owner_type', Team::class)
                        ->whereIn('owner_id', $manageableTeamIds)
                        ->pluck('id')
                );
            }
        }

        return self::$memo[$key] = NotificationChannel::query()
            ->whereIn('id', $ids->unique()->values()->all())
            ->withCount('subscriptions')
            ->orderBy('label')
            ->get();
    }

    public static function flushMemo(?string $userId = null, ?string $organizationId = null): void
    {
        if ($userId === null) {
            self::$memo = [];

            return;
        }

        $prefix = $userId.'|';
        if ($organizationId !== null) {
            unset(self::$memo[$prefix.$organizationId]);

            return;
        }

        foreach (array_keys(self::$memo) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$memo[$key]);
            }
        }
    }
}
