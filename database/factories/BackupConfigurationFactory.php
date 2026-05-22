<?php

namespace Database\Factories;

use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupConfiguration>
 */
class BackupConfigurationFactory extends Factory
{
    protected $model = BackupConfiguration::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'created_by_user_id' => null,
            'name' => fake()->words(2, true),
            'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
            'config' => [
                'access_key' => 'AKIA'.fake()->sha1(),
                'secret' => fake()->sha256(),
                'bucket' => fake()->slug(2),
                'region' => 'us-east-1',
                'endpoint' => 'https://s3.example.com',
                'use_path_style' => false,
            ],
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by_user_id' => $user->id,
        ]);
    }
}
