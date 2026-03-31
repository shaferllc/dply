<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsEvent;
use App\Actions\Decorators\EventDecorator;
use Illuminate\Events\Dispatcher;

class EventDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsEvent::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->matches(Dispatcher::class, 'dispatch')
            || $frame->matches(Dispatcher::class, 'listen');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(EventDecorator::class, ['action' => $instance]);
    }
}
