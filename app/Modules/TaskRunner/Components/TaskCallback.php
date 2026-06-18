<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Components;

use Illuminate\View\Component;

class TaskCallback extends Component
{
    public function __construct(public string $bashFunction, public string $url, public string $body) {}

    public function render()
    {
        return view('task-runner::task-callback');
    }
}
