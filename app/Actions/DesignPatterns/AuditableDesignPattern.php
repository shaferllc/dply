<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsAuditable;
use App\Actions\Decorators\AuditableDecorator;

class AuditableDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsAuditable::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(AuditableDecorator::class, ['action' => $instance]);
    }
}
