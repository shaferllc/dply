<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRequiresSubscription;
use App\Actions\Decorators\RequiresSubscriptionDecorator;

class RequiresSubscriptionDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRequiresSubscription::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RequiresSubscriptionDecorator::class, ['action' => $instance]);
    }
}
