<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteFileBackup>
 */
class SiteFileBackupFactory extends Factory
{
    protected $model = SiteFileBackup::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteFileBackup::STATUS_COMPLETED,
            'disk_path' => 'site-file-backups/fake/fake.tar.gz',
            'bytes' => 1024,
        ]);
    }
}
