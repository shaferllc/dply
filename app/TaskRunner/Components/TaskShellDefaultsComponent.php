<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class TaskShellDefaultsComponent extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(public bool $exitImmediately = true) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $setOptions = array_filter([
            $this->exitImmediately ? 'e' : null,
            'x',
        ]);

        return view('task-runner::components.task-shell-defaults', [
            'setOptions' => implode($setOptions),
        ]);
    }
}
