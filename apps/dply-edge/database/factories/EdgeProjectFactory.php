<?php

namespace Database\Factories;

use App\Models\EdgeProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EdgeProject>
 */
class EdgeProjectFactory extends Factory
{
    protected $model = EdgeProject::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
        ];
    }
}
