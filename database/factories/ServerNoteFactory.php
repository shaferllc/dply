<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerNote>
 */
class ServerNoteFactory extends Factory
{
    protected $model = ServerNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'body' => "## {$this->faker->sentence(3)}\n\n{$this->faker->paragraph()}",
            'pinned' => false,
            'tags' => null,
            'archived_at' => null,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn (): array => ['pinned' => true]);
    }

    /** @param  array<int, string>  $tags */
    public function tagged(array $tags): static
    {
        return $this->state(fn (): array => ['tags' => $tags]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
            'pinned' => false,
        ]);
    }
}
