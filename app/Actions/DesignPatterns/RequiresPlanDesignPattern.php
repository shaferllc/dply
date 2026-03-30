<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRequiresPlan;
use App\Actions\Decorators\RequiresPlanDecorator;

class RequiresPlanDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRequiresPlan::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RequiresPlanDecorator::class, ['action' => $instance]);
    }
}
