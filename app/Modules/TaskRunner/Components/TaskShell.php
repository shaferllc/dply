<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Components;

use Illuminate\View\Component;

class TaskShell extends Component
{
    public function __construct(public string $setOptions) {}

    public function render()
    {
        return view('task-runner::task-shell');
    }
}
