<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudWorkerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_provisioning_queue_worker(): void
    {
        $worker = CloudWorker::factory()->create();

        $this->assertSame(CloudWorker::TYPE_WORKER, $worker->type);
        $this->assertSame(CloudWorker::STATUS_PROVISIONING, $worker->status);
        $this->assertFalse($worker->isScheduler());
        $this->assertFalse($worker->isActive());
    }

    public function test_scheduler_factory_state(): void
    {
        $worker = CloudWorker::factory()->scheduler()->create();

        $this->assertSame(CloudWorker::TYPE_SCHEDULER, $worker->type);
        $this->assertTrue($worker->isScheduler());
    }

    public function test_worker_effective_command_uses_stored_command(): void
    {
        $worker = CloudWorker::factory()->make(['command' => 'php artisan horizon']);

        $this->assertSame('php artisan horizon', $worker->effectiveCommand());
    }

    public function test_worker_effective_command_falls_back_to_default(): void
    {
        $worker = CloudWorker::factory()->make(['type' => CloudWorker::TYPE_WORKER, 'command' => '']);

        $this->assertSame('php artisan queue:work', $worker->effectiveCommand());
    }

    public function test_scheduler_effective_command_is_always_schedule_work(): void
    {
        // Even with a bogus stored command, the scheduler runs schedule:work.
        $worker = CloudWorker::factory()->make([
            'type' => CloudWorker::TYPE_SCHEDULER,
            'command' => 'php artisan queue:work',
        ]);

        $this->assertSame('php artisan schedule:work', $worker->effectiveCommand());
    }

    public function test_scheduler_effective_instance_count_is_always_one(): void
    {
        $worker = CloudWorker::factory()->make([
            'type' => CloudWorker::TYPE_SCHEDULER,
            'instance_count' => 5,
        ]);

        $this->assertSame(1, $worker->effectiveInstanceCount());
    }

    public function test_worker_effective_instance_count_respects_stored_value(): void
    {
        $worker = CloudWorker::factory()->make(['type' => CloudWorker::TYPE_WORKER, 'instance_count' => 4]);

        $this->assertSame(4, $worker->effectiveInstanceCount());
    }

    public function test_worker_effective_instance_count_floors_at_one(): void
    {
        $worker = CloudWorker::factory()->make(['type' => CloudWorker::TYPE_WORKER, 'instance_count' => 0]);

        $this->assertSame(1, $worker->effectiveInstanceCount());
    }

    public function test_size_tier_maps_to_do_size_slug(): void
    {
        $this->assertSame('basic-xxs', CloudWorker::factory()->make(['size' => 'small'])->backendSizeSlug());
        $this->assertSame('basic-xs', CloudWorker::factory()->make(['size' => 'medium'])->backendSizeSlug());
        $this->assertSame('basic-s', CloudWorker::factory()->make(['size' => 'large'])->backendSizeSlug());
        $this->assertSame('basic-m', CloudWorker::factory()->make(['size' => 'xlarge'])->backendSizeSlug());
        $this->assertSame('basic-xxs', CloudWorker::factory()->make(['size' => 'bogus'])->backendSizeSlug());
    }

    public function test_site_relation(): void
    {
        $site = Site::factory()->create();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

        $this->assertTrue($worker->site->is($site));
    }
}
