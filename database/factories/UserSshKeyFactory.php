<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSshKey>
 */
class UserSshKeyFactory extends Factory
{
    protected $model = UserSshKey::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('a', 43).' test-key',
            'provision_on_new_servers' => false,
        ];
    }
}
