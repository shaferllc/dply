<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class EnsureUserHasWorkspaceOrganization
{
    public static function run(User $user): Organization
    {
        $org = $user->organizations()->orderByPivot('created_at')->orderBy('organizations.id')->first();

        if ($org) {
            $org->createDefaultTeamIfMissing();
            $org->attachUserToDefaultTeam($user);

            return $org;
        }

        $name = self::workspaceNameFor($user);
        $slug = self::uniqueSlugFor($user);

        $org = Organization::query()->create([
            'name' => $name,
            'slug' => $slug,
            'email' => $user->email,
        ]);

        $org->users()->attach($user->id, ['role' => 'owner']);
        $org->attachUserToDefaultTeam($user);

        return $org;
    }

    public static function workspaceNameFor(User $user): string
    {
        return "{$user->name}'s Workspace";
    }

    private static function uniqueSlugFor(User $user): string
    {
        $base = Str::slug(Str::limit($user->name, 40)) ?: 'workspace';
        $slug = $base;
        $i = 0;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
