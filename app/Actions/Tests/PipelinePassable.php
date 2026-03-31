<?php

declare(strict_types=1);

namespace App\Actions\Tests;

class PipelinePassable
{
    public function __construct(public int $count = 0)
    {
        //
    }

    public function increment(): void
    {
        $this->count++;
    }
}
