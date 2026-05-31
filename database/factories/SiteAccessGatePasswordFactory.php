<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteAccessGatePassword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteAccessGatePassword>
 */
class SiteAccessGatePasswordFactory extends Factory
{
    protected $model = SiteAccessGatePassword::class;

    public function definition(): array
    {
        $salt = bin2hex(random_bytes(16));
        $password = 'gatepassword1';

        return [
            'site_id' => Site::factory(),
            'label' => fake()->unique()->firstName(),
            'password_salt' => $salt,
            'password_verifier' => hash('sha256', $salt.$password),
            'sort_order' => 0,
        ];
    }
}
