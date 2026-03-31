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

    /**
     * Hosted metadata required for deploy (ADR-007).
     */
    public function hosted(): static
    {
        return $this->state(fn (): array => [
            'settings' => [
                'runtime' => 'hosted',
                'environment_id' => 'env-factory-'.fake()->unique()->numerify('########'),
            ],
        ]);
    }
}
