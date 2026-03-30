<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsVersioned;
use App\Actions\Decorators\VersionDecorator;

class VersionDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsVersioned::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize - versioning should work on every execution
        // regardless of how the action is called
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        // Create decorator directly to avoid container resolution triggering re-decoration
        return new VersionDecorator($instance);
    }
}
