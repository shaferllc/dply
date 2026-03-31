<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsApiResponse;
use App\Actions\Decorators\ApiResponseDecorator;

class ApiResponseDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsApiResponse::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ApiResponseDecorator::class, ['action' => $instance]);
    }
}
