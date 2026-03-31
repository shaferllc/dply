<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsTimeout;
use App\Actions\Decorators\TimeoutDecorator;

/**
 * Timeout Design Pattern
 *
 * Recognizes actions that use the AsTimeout trait and wraps them
 * with TimeoutDecorator to automatically enforce execution timeouts.
 */
class TimeoutDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsTimeout::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return new TimeoutDecorator($instance);
    }
}
