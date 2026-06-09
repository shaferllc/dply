<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBlueprint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerBlueprint>
 */
class ServerBlueprintFactory extends Factory
{
    protected $model = ServerBlueprint::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'source_server_id' => null,
            'created_by_user_id' => User::factory(),
            'name' => fake()->words(3, true).' stack',
            'snapshot' => [
                'version' => 1,
                'stack' => [
                    'database' => 'mysql84',
                    'database_version' => '8.4',
                    'php_version' => '8.4',
                    'webserver' => 'nginx',
                    'cache_service' => 'redis',
                    'low_mem_mode' => false,
                    'total_memory_mb' => 4096,
                    'swap_mb' => 512,
                ],
                'server_role' => 'application',
                'install_profile' => 'laravel_app',
                'runtime_defaults' => [],
                'firewall_rules' => [],
                'supervisor_programs' => [],
            ],
        ];
    }

    public function fromServer(Server $server): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $server->organization_id,
            'source_server_id' => $server->id,
        ]);
    }
}
