<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsTransformer;
use App\Actions\Decorators\TransformerDecorator;

class TransformerDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsTransformer::class;
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
        return new TransformerDecorator($instance);
    }
}
