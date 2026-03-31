<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Script>
 */
class ScriptFactory extends Factory
{
    protected $model = Script::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'content' => "#!/bin/bash\nset -e\necho \"Hello from Dply script\"\n",
            'run_as_user' => null,
            'source' => Script::SOURCE_USER_CREATED,
            'marketplace_key' => null,
        ];
    }

    public function forOrganization(Organization $organization, User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }
}
