<?php

namespace Database\Factories;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->slug(2),
            'provider' => ServerProvider::Custom,
            'ip_address' => fake()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'status' => Server::STATUS_READY,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Server::STATUS_PENDING]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Server::STATUS_READY]);
    }

    public function digitalOcean(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => ServerProvider::DigitalOcean,
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
        ]);
    }
}
