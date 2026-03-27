<?php

namespace Database\Factories;

use App\Models\ServerlessProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServerlessProject>
 */
class ServerlessProjectFactory extends Factory
{
    protected $model = ServerlessProject::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
        ];
    }
}
