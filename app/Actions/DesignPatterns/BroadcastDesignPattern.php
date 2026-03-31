<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsBroadcast;
use App\Actions\Decorators\BroadcastDecorator;

class BroadcastDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsBroadcast::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(BroadcastDecorator::class, ['action' => $instance]);
    }
}
