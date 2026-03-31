<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsTestable;
use App\Actions\Decorators\TestableDecorator;

/**
 * Testable Design Pattern
 *
 * Recognizes actions that use the AsTestable trait and wraps them
 * with TestableDecorator to automatically track execution for testing.
 */
class TestableDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsTestable::class;
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
        return new TestableDecorator($instance);
    }
}
