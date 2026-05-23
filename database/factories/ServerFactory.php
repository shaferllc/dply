<?php

namespace Database\Factories;

use App\Enums\ServerProvider;
use App\Models\Organization;
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
            // Servers in production always belong to an Organization (NOT NULL on the column).
            // Without auto-creating one here, tests that just do `Server::factory()->create()`
            // produce orgless servers; then `Site::factory()->create()` for them fails because
            // the Site::creating hook can't inherit `organization_id` from the parent.
            'organization_id' => Organization::factory(),
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

    public function hetzner(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => ServerProvider::Hetzner,
            'region' => 'fsn1',
            'size' => 'cx22',
        ]);
    }
}
