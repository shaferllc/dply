<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Tests\TestCase;

class TrackTaskInBackgroundTimeoutTest extends TestCase
{
    public function test_wrapper_timeout_stays_within_validation_limit(): void
    {
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

        $this->assertSame(3600, $task->getTimeout());

        try {
            $task->validate();
        } catch (TaskValidationException $e) {
            $this->fail('Wrapper timeout should remain valid at the ceiling: '.$e->getMessage());
        }
    }
}
