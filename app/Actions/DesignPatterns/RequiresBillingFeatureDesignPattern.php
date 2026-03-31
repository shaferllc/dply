<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRequiresBillingFeature;
use App\Actions\Decorators\RequiresBillingFeatureDecorator;

class RequiresBillingFeatureDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRequiresBillingFeature::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RequiresBillingFeatureDecorator::class, ['action' => $instance]);
    }
}
