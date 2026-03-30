<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tasks;

use App\Modules\TaskRunner\Task;

/**
 * GetFile task migrated from the Tasks module.
 * Retrieves file contents from a remote server.
 */
class GetFile extends Task
{
    protected ?int $timeout = 30;

    public function __construct(public string $path, public ?int $lines = null) {}

    /**
     * The command to run.
     */
    public function render(): string
    {
        if ($this->lines) {
            return '
                currentscript="$0"

                tail -n '.$this->lines.' '.$this->path.'

                shred -u $currentscript
                shred -u .'.$this->path;
        }

        return '
            currentscript="$0"

            tail -c 1M '.$this->path.'

            shred -u $currentscript
            shred -u .'.$this->path;
    }
}
