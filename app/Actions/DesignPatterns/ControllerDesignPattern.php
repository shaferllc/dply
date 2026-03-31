<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsController;
use App\Actions\Decorators\ControllerDecorator;
use Illuminate\Routing\Route;

class ControllerDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsController::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->matches(Route::class, 'getController');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ControllerDecorator::class, [
            'action' => $instance,
            'route' => $frame->getObject(),
        ]);
    }
}
