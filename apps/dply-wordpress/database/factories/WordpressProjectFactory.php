<?php

namespace Database\Factories;

use App\Models\WordpressProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WordpressProject>
 */
class WordpressProjectFactory extends Factory
{
    protected $model = WordpressProject::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
        ];
    }
}
