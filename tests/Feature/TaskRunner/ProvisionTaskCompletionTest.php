<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionTaskCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_observer_marks_server_done_when_provision_task_finishes(): void
    {
        $server = Server::factory()->create([
            'setup_status' => Server::SETUP_STATUS_RUNNING,
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'status' => TaskStatus::Running,
            'server_id' => $server->id,
            'script' => 'x',
            'timeout' => 300,
            'user' => 'root',
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
        ]);

        $task->update([
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
            'completed_at' => now(),
        ]);

        $server->refresh();
        $this->assertSame(Server::SETUP_STATUS_DONE, $server->setup_status);
    }

    public function test_observer_marks_server_failed_when_provision_task_fails(): void
    {
        $server = Server::factory()->create([
            'setup_status' => Server::SETUP_STATUS_RUNNING,
        ]);

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'status' => TaskStatus::Running,
            'server_id' => $server->id,
            'script' => 'x',
            'timeout' => 300,
            'user' => 'root',
        ]);

        $server->update([
            'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
        ]);

        $task->update([
            'status' => TaskStatus::Failed,
            'exit_code' => 1,
            'completed_at' => now(),
        ]);

        $server->refresh();
        $this->assertSame(Server::SETUP_STATUS_FAILED, $server->setup_status);
    }

    public function test_apply_provision_outcome_sets_deploy_ssh_user_when_key_present(): void
    {
        $keyPath = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');
        $this->assertFileExists($keyPath);

        $server = Server::factory()->create([
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'ssh_user' => 'root',
            'ssh_private_key' => file_get_contents($keyPath),
        ]);

        $this->assertNotNull($server->openSshPublicKeyFromPrivate());

        RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

        $server->refresh();
        $this->assertSame(Server::SETUP_STATUS_DONE, $server->setup_status);
        $deployUser = config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser !== '' && $deployUser !== 'root') {
            $this->assertSame($deployUser, $server->ssh_user);
        }
    }
}
