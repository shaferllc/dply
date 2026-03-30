<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsApiVersion;
use App\Actions\Decorators\ApiVersionDecorator;

class ApiVersionDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsApiVersion::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ApiVersionDecorator::class, ['action' => $instance]);
    }
}
