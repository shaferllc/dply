<?php

namespace Database\Factories;

use App\Models\CloudDatabase;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CloudDatabase>
 */
class CloudDatabaseFactory extends Factory
{
    protected $model = CloudDatabase::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'db-'.Str::lower(Str::random(6)),
            'engine' => CloudDatabase::ENGINE_POSTGRES,
            'version' => '16',
            'size' => 'small',
            'region' => 'nyc1',
            'backend' => CloudDatabase::BACKEND_DIGITALOCEAN,
            'backend_id' => null,
            'provider_credential_id' => null,
            'status' => CloudDatabase::STATUS_PROVISIONING,
            'connection' => null,
            'meta' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => CloudDatabase::STATUS_ACTIVE,
            'backend_id' => 'do-db-'.Str::lower(Str::random(8)),
            'connection' => [
                'host' => 'db.example.ondigitalocean.com',
                'port' => 25060,
                'username' => 'doadmin',
                'password' => 'secret-pass',
                'database' => 'defaultdb',
            ],
        ]);
    }

    public function mysql(): static
    {
        return $this->state(fn (): array => [
            'engine' => CloudDatabase::ENGINE_MYSQL,
            'version' => '8',
        ]);
    }

    public function redis(): static
    {
        return $this->state(fn (): array => [
            'engine' => CloudDatabase::ENGINE_REDIS,
            'version' => '7',
        ]);
    }
}
