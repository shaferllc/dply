<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsTracer;
use App\Actions\Decorators\TracerDecorator;

/**
 * Tracer Design Pattern
 *
 * Recognizes actions that use the AsTracer trait and wraps them
 * with TracerDecorator to automatically add distributed tracing support.
 */
class TracerDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsTracer::class;
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
        return new TracerDecorator($instance);
    }
}
