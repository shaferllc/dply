<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(MarketplaceItemSeeder::class);

        // User::factory(10)->create();

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        if (App::environment('local')) {
            $this->seedLocalDevAdmin();
            $this->call(LocalDemoServersSeeder::class);
        }
    }

    /**
     * Local development: ensure tj@tjshafer.com exists with a normal workspace organization
     * (password: "password"). Legacy rows with slug "local-dev" are renamed in place.
     */
    protected function seedLocalDevAdmin(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'tj@tjshafer.com'],
            [
                'name' => 'TJ Shafer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

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
    }
}
