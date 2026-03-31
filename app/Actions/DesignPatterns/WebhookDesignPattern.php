<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsWebhook;
use App\Actions\Decorators\WebhookDecorator;

class WebhookDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsWebhook::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Don't apply webhooks when running in console
        // This prevents WebhookDecorator from being applied to commands
        // CommandDecorator must be the outermost decorator for commands
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize - webhooks should fire on every execution
        // regardless of how the action is called
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        // Create decorator directly to avoid container resolution triggering re-decoration
        return new WebhookDecorator($instance);
    }
}
