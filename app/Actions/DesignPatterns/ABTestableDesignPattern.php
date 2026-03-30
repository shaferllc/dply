<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsABTestable;
use App\Actions\Decorators\ABTestableDecorator;

class ABTestableDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsABTestable::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ABTestableDecorator::class, ['action' => $instance]);
    }
}
