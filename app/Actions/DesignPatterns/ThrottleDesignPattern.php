<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsThrottle;
use App\Actions\Decorators\ThrottleDecorator;

/**
 * Throttle Design Pattern
 *
 * Recognizes actions that use the AsThrottle trait and wraps them
 * with ThrottleDecorator to automatically limit concurrent executions.
 */
class ThrottleDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsThrottle::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        return true;
    }

    public function decorate(mixed $instance, BacktraceFrame $frame): mixed
    {
        return new ThrottleDecorator($instance);
    }
}
