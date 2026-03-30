<?php

namespace Database\Factories;

use App\Models\BackupConfiguration;
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
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
            'config' => [
                'access_key' => 'AKIA'.fake()->sha1(),
                'secret' => fake()->sha256(),
                'bucket' => fake()->slug(2),
                'region' => 'us-east-1',
                'endpoint' => '',
                'use_path_style' => false,
            ],
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
