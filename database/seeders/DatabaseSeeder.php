<?php

namespace Database\Seeders;

use App\Actions\Auth\EnsureLocalDevAdminUser;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

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
            EnsureLocalDevAdminUser::run();
            $this->call(LocalDemoServersSeeder::class);
        }

<<<<<<< Updated upstream
        $this->call(ScriptSeeder::class);
=======
    /**
     * Local development: ensure tj@tjshafer.com exists with a normal workspace organization
     * (password: "password"). Legacy rows with slug "local-dev" are renamed in place.
     */
    protected function seedLocalDevAdmin(): void
    {
        EnsureLocalDevAdminUser::run();
>>>>>>> Stashed changes
    }
}
