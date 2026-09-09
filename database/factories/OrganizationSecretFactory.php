<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationSecret>
 */
class OrganizationSecretFactory extends Factory
{
    protected $model = OrganizationSecret::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'key' => strtoupper(fake()->unique()->lexify('SECRET_????')),
            'value' => fake()->sha256(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
