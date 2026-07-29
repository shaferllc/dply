<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerDatabase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerDatabase>
 */
class ServerDatabaseFactory extends Factory
{
    protected $model = ServerDatabase::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'site_id' => null,
            'name' => $this->faker->unique()->slug(1),
            'engine' => 'postgres',
            'username' => $this->faker->userName(),
            'password' => 'secret',
            // Remote access is off by default: it opens the database port to a
            // CIDR, so a factory that enabled it silently would make permissive
            // firewall behaviour the default in every test.
            'remote_access' => false,
            'allowed_from' => null,
        ];
    }
}
