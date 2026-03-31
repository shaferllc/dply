<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRequiresRole;
use App\Actions\Decorators\RequiresRoleDecorator;

class RequiresRoleDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRequiresRole::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RequiresRoleDecorator::class, ['action' => $instance]);
    }
}
