<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;

class AsPipelineTestAction extends Actions
{
    public function handle(PipelinePassable $passable): void
    {
        $passable->increment();
    }

    public function asPipeline(PipelinePassable $passable): void
    {
        $this->handle($passable);
    }
}
