<?php

declare(strict_types=1);

namespace Tests\Unit\TrackTaskInBackgroundTimeoutTest;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;

test('wrapper timeout stays within validation limit', function () {
    $task = new TrackTaskInBackground(
        new class extends TestTask
        {
            public function getTimeout(): int
            {
                return 3600;
            }
        },
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    expect($task->getTimeout())->toBe(3600);

    try {
        $task->validate();
    } catch (TaskValidationException $e) {
        $this->fail('Wrapper timeout should remain valid at the ceiling: '.$e->getMessage());
    }
});
