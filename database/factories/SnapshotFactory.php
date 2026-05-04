<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\Snapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Snapshot>
 */
class SnapshotFactory extends Factory
{
    protected $model = Snapshot::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'destination' => Snapshot::DESTINATION_LOCAL_DISK,
            'local_path' => '/home/dply/snapshots/'.fake()->uuid().'.sql.gz',
            's3_bucket' => null,
            's3_key' => null,
            'bytes' => fake()->numberBetween(1024, 10_000_000),
            'engine' => 'mysql',
            'reason' => Snapshot::REASON_MANUAL,
            'taken_by_user_id' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function s3(): self
    {
        return $this->state(fn () => [
            'destination' => Snapshot::DESTINATION_S3,
            'local_path' => null,
            's3_bucket' => 'my-dply-backups',
            's3_key' => 'site-abc/'.fake()->uuid().'.sql.gz',
            'expires_at' => null,
        ]);
    }

    public function preMigrationRollback(): self
    {
        return $this->state(fn () => [
            'reason' => Snapshot::REASON_PRE_MIGRATION_ROLLBACK,
        ]);
    }
}
