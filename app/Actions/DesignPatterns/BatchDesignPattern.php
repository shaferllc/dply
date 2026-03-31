<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsBatch;
use App\Actions\Decorators\BatchDecorator;

class BatchDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsBatch::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(BatchDecorator::class, ['action' => $instance]);
    }
}
