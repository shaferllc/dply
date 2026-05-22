<?php

declare(strict_types=1);

namespace Tests\Unit\TrackTaskInBackgroundScriptTest;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('wrapper script renders even with shell expansion syntax', function () {
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
        'status' => TaskStatus::Pending,
        'script' => 'wrapper.sh',
        'timeout' => 300,
        'user' => 'root',
    ]);

    $actualTask->setTaskModel($taskModel);
    $wrapper->setTaskModel($taskModel);

    $script = $wrapper->getScript();

    expect($script)->toBeString();
    $this->assertStringContainsString('PATH_ACTUAL_SCRIPT', $script);
    $this->assertStringContainsString('httpPostSilently', $script);
});
