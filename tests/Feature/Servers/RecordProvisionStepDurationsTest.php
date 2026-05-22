<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Actions\Servers\RecordProvisionStepDurations;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionStepRun;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ProvisionStepSnapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordProvisionStepDurationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeProvisionTask(Server $server, string $output): Task
    {
        return Task::query()->create([
            'name' => 'Provision stack',
            'action' => 'provision_stack',
            'script' => 'remote',
            'status' => TaskStatus::Finished,
            'server_id' => $server->id,
            'output' => $output,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function test_inserts_one_row_per_step_end_marker(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $task = $this->makeProvisionTask($server, implode("\n", [
            '[dply-step] Installing system updates',
            '[dply-step-end] Installing system updates'."\t".'45',
            '[dply-step] Installing MySQL',
            '[dply-step-end] Installing MySQL'."\t".'120',
            '',
        ]));

        $count = app(RecordProvisionStepDurations::class)->handle($server, $task);

        $this->assertSame(2, $count);
        $rows = ServerProvisionStepRun::query()->where('task_id', $task->id)->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['Installing system updates', 'Installing MySQL'],
            $rows->pluck('label')->all(),
        );
        $this->assertEqualsCanonicalizing([45, 120], $rows->pluck('duration_seconds')->all());
        $this->assertSame($org->id, $rows->first()->organization_id);
    }

    public function test_marks_resumed_steps_with_resumed_flag(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $task = $this->makeProvisionTask($server, implode("\n", [
            '[dply-step-end] Installing MySQL'."\t".'0'."\t".'resumed',
            '',
        ]));

        app(RecordProvisionStepDurations::class)->handle($server, $task);

        $row = ServerProvisionStepRun::query()->where('task_id', $task->id)->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->resumed);
        $this->assertSame(0, $row->duration_seconds);
        $this->assertSame(
            ProvisionStepSnapshots::keyForLabel('Installing MySQL'),
            $row->label_hash,
        );
    }

    public function test_is_idempotent_when_called_twice_for_same_task(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $task = $this->makeProvisionTask($server, "[dply-step-end] Installing MySQL\t60\n");

        $first = app(RecordProvisionStepDurations::class)->handle($server, $task);
        $second = app(RecordProvisionStepDurations::class)->handle($server, $task);

        $this->assertSame(1, $first, 'First call inserts the new row.');
        $this->assertSame(0, $second, 'Second call must skip the duplicate (task_id, label_hash).');
        $this->assertSame(
            1,
            ServerProvisionStepRun::query()->where('task_id', $task->id)->count(),
        );
    }
}
