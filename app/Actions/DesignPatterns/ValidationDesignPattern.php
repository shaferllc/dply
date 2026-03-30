<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsValidated;
use App\Actions\Decorators\ValidationDecorator;

class ValidationDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsValidated::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize - validation should run on every execution
        // regardless of how the action is called
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        // Create decorator directly to avoid container resolution triggering re-decoration
        return new ValidationDecorator($instance);
    }
}
