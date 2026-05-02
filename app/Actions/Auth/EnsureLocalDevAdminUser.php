<?php

namespace App\Actions\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnsureLocalDevAdminUser
{
    /**
     * Ensure tj@tjshafer.com exists with a normal workspace organization
     * (password: "password"). Legacy rows with slug "local-dev" are renamed in place.
     */
    public static function run(): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'tj@tjshafer.com'],
            [
                'name' => 'TJ Shafer',
                'password' => Hash::make('password'),
            ]
        );

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $workspaceName = trim($user->name).'’s workspace';

        $legacy = Organization::query()->where('slug', 'local-dev')->first();

        $baseSlug = Str::slug(str_replace('@', '-at-', $user->email));
        $slug = $baseSlug;
        $suffix = 0;
        while (Organization::query()
            ->where('slug', $slug)
            ->when($legacy, fn ($q) => $q->where('id', '!=', $legacy->id))
            ->exists()) {
            $suffix++;
            $slug = $baseSlug.'-'.$suffix;
        }

        if ($legacy) {
            $legacy->forceFill([
                'name' => $workspaceName,
                'slug' => $slug,
                'email' => $user->email,
            ])->save();
            $org = $legacy->fresh();
        } else {
            $org = Organization::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $workspaceName,
                    'email' => $user->email,
                ]
            );
        }

        if (! $org->users()->where('user_id', $user->id)->exists()) {
            $org->users()->attach($user->id, ['role' => 'owner']);
        } else {
            $org->users()->updateExistingPivot($user->id, ['role' => 'owner']);
        }

        $org->createDefaultTeamIfMissing();
        $org->attachUserToDefaultTeam($user);

        return $user->fresh();
    }
}
