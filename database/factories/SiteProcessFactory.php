<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteProcess>
 */
class SiteProcessFactory extends Factory
{
    protected $model = SiteProcess::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'echo worker',
            'scale' => 1,
            'env_vars' => null,
            'working_directory' => null,
            'user' => null,
            'is_active' => true,
        ];
    }

    public function web(): static
    {
        return $this->state(fn () => [
            'type' => SiteProcess::TYPE_WEB,
            'name' => SiteProcess::TYPE_WEB,
            'command' => null,
        ]);
    }

    public function scheduler(): static
    {
        return $this->state(fn () => [
            'type' => SiteProcess::TYPE_SCHEDULER,
            'name' => 'scheduler',
            'command' => 'php artisan schedule:work',
        ]);
    }
}
