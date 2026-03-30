<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsAuthenticated;
use App\Actions\Decorators\AuthenticatedDecorator;

class AuthenticatedDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsAuthenticated::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(AuthenticatedDecorator::class, ['action' => $instance]);
    }
}
