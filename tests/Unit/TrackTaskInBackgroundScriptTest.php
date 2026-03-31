<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackTaskInBackgroundScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrapper_script_renders_even_with_shell_expansion_syntax(): void
    {
        $actualTask = new TestTask;
        $wrapper = new TrackTaskInBackground(
            $actualTask,
            'https://example.com/finished',
            'https://example.com/failed',
            'https://example.com/timeout',
        );

        $taskModel = TaskModel::query()->create([
            'name' => 'Wrapper Task',
            'action' => 'provision_stack',
            'status' => \App\Modules\TaskRunner\Enums\TaskStatus::Pending,
            'script' => 'wrapper.sh',
            'timeout' => 300,
            'user' => 'root',
        ]);

        $actualTask->setTaskModel($taskModel);
        $wrapper->setTaskModel($taskModel);

        $script = $wrapper->getScript();

        $this->assertIsString($script);
        $this->assertStringContainsString('PATH_ACTUAL_SCRIPT', $script);
        $this->assertStringContainsString('httpPostSilently', $script);
    }
}
