<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Helpers;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Traits\HandlesCallbacks;

/**
 * Concrete test task class for testing purposes.
 */
class TestTask extends Task implements HasCallbacks
{
    use HandlesCallbacks;

    public string $name = 'Test Task';

    public string $action = 'test_action';

    public ?int $timeout = 60;

    public string $view = 'test-view';

    public string $script = 'echo "Hello World"';

    public function __construct(string $name = 'Test Task')
    {
        $this->name = $name;
    }

    public function getScript(): string
    {
        return $this->script;
    }

    public function getView(): string
    {
        return $this->view;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTimeout(): ?int
    {
        return $this->timeout;
    }
}
