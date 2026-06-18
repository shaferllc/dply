<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRequiresCapability;
use App\Actions\Decorators\RequiresCapabilityDecorator;

class RequiresCapabilityDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRequiresCapability::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate(mixed $instance, BacktraceFrame $frame): mixed
    {
        return app(RequiresCapabilityDecorator::class, ['action' => $instance]);
    }
}
