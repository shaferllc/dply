<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
{
    protected $model = StatusPage::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' Status',
            'description' => fake()->optional()->sentence(),
            'is_public' => true,
        ];
    }
}
