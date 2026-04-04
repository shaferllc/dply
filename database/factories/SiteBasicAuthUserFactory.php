<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<SiteBasicAuthUser>
 */
class SiteBasicAuthUserFactory extends Factory
{
    protected $model = SiteBasicAuthUser::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'username' => fake()->unique()->userName(),
            'password_hash' => Hash::make('password'),
            'path' => '/',
            'sort_order' => 0,
        ];
    }
}
