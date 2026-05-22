<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy;

use App\Exceptions\ServerlessDeployCancelledException;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\ServerlessDeployProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerlessDeployProgressTest extends TestCase
{
    use RefreshDatabase;

    private function runningDeployment(Site $site): SiteDeployment
    {
        return SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function test_it_upserts_steps_into_the_running_deployment(): void
    {
        $site = Site::factory()->create();
        $deployment = $this->runningDeployment($site);

        $progress = new ServerlessDeployProgress;
        $progress->active($site, 'checkout', 'Cloning repository');
        $progress->done($site, 'checkout', 'Cloned repository');
        $progress->active($site, 'upload', 'Uploading to DigitalOcean Functions');

        $steps = $deployment->fresh()->phaseSteps(ServerlessDeployProgress::PHASE);

        $this->assertCount(2, $steps, 'checkout should upsert, not append');
        $this->assertSame('done', $steps[0]['state']);
        $this->assertSame('Cloned repository', $steps[0]['label']);
        $this->assertTrue($steps[0]['ok']);
        $this->assertIsInt($steps[0]['duration_ms'], 'a finished step records its duration');
        $this->assertSame('active', $steps[1]['state']);
        $this->assertFalse($steps[1]['ok']);
        $this->assertNull($steps[1]['duration_ms'], 'an in-flight step has no duration yet');
    }

    public function test_it_is_a_no_op_without_a_running_deployment(): void
    {
        $site = Site::factory()->create();

        (new ServerlessDeployProgress)->active($site, 'checkout', 'Cloning repository');

        $this->assertSame(0, SiteDeployment::query()->count());
    }

    public function test_it_ignores_a_finished_deployment(): void
    {
        $site = Site::factory()->create();
        $finished = $this->runningDeployment($site);
        $finished->update(['status' => SiteDeployment::STATUS_SUCCESS, 'finished_at' => now()]);

        (new ServerlessDeployProgress)->active($site, 'checkout', 'Cloning repository');

        $this->assertSame([], $finished->fresh()->phaseSteps(ServerlessDeployProgress::PHASE));
    }

    public function test_checkpoint_aborts_when_cancellation_is_requested(): void
    {
        $site = Site::factory()->create();
        $deployment = $this->runningDeployment($site);

        $progress = new ServerlessDeployProgress;
        $progress->requestCancel($site, $deployment->id);

        $this->expectException(ServerlessDeployCancelledException::class);
        $progress->checkpoint($site);
    }

    public function test_checkpoint_is_a_no_op_without_a_cancel_request(): void
    {
        $site = Site::factory()->create();
        $this->runningDeployment($site);

        (new ServerlessDeployProgress)->checkpoint($site);

        $this->expectNotToPerformAssertions();
    }

    public function test_checkpoint_ignores_a_stale_request_for_a_different_deployment(): void
    {
        $site = Site::factory()->create();
        $current = $this->runningDeployment($site);

        // A cancel request left over from an earlier deployment must not
        // abort the current run.
        (new ServerlessDeployProgress)->requestCancel($site, 'an-old-deployment-id');
        (new ServerlessDeployProgress)->checkpoint($site);

        $this->assertSame(SiteDeployment::STATUS_RUNNING, $current->fresh()->status);
    }
}
