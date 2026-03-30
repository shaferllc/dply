<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => fake()->words(2, true),
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/'.fake()->sha256(),
                'channel' => null,
            ],
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);
    }
}
