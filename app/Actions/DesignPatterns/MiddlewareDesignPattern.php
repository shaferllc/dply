<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsMiddleware;
use App\Actions\Decorators\MiddlewareDecorator;
use Illuminate\Http\Kernel;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;

class MiddlewareDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsMiddleware::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->matches(Router::class, 'runRouteWithinStack')
            || $frame->matches(Pipeline::class, 'then')
            || $frame->instanceOf(Kernel::class);
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(MiddlewareDecorator::class, ['action' => $instance]);
    }
}
