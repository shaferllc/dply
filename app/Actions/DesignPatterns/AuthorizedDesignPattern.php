<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsAuthorized;
use App\Actions\Decorators\AuthorizedDecorator;

class AuthorizedDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsAuthorized::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(AuthorizedDecorator::class, ['action' => $instance]);
    }
}
