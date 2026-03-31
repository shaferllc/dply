<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsUpdatable;
use App\Actions\Decorators\UpdateDecorator;

class UpdateDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsUpdatable::class;
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
        return new UpdateDecorator($instance);
    }
}
