<?php

namespace Database\Factories;

use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    protected $model = ProviderCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'digitalocean',
            'name' => 'Test DO',
            'credentials' => ['api_token' => 'dop_v1_'.fake()->sha1()],
        ];
    }
}
