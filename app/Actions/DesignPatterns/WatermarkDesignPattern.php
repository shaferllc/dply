<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsWatermarked;
use App\Actions\Decorators\WatermarkDecorator;

class WatermarkDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsWatermarked::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Don't apply watermarks when running in console
        // This prevents WatermarkDecorator from being applied to commands
        // CommandDecorator must be the outermost decorator for commands
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize - watermarks should be applied on every execution
        // regardless of how the action is called
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        // Create decorator directly to avoid container resolution triggering re-decoration
        return new WatermarkDecorator($instance);
    }
}
