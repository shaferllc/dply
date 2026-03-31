<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsCommand;
use App\Actions\Decorators\CommandDecorator;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;

class CommandDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsCommand::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Recognize when frame matches Application::resolve or Schedule::command
        if ($frame->matches(Application::class, 'resolve')
            || $frame->matches(Schedule::class, 'command')) {
            return true;
        }

        // Also recognize when running in console and frame is empty
        // This handles cases where identifyAndDecorate is called with empty frame
        if (app()->runningInConsole() && ! $frame->fromClass()) {
            return true;
        }

        return false;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        // Unwrap any existing decorators to get the original action
        // CommandDecorator must be the outermost decorator
        $originalAction = $this->unwrapDecorator($instance);

        // Create CommandDecorator directly (not through container) to avoid decoration
        return new CommandDecorator($originalAction);
    }

    protected function unwrapDecorator($instance)
    {
        // If instance is a decorator, get the wrapped action
        if (str_starts_with(get_class($instance), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($instance);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $wrappedAction = $property->getValue($instance);

                return $this->unwrapDecorator($wrappedAction);
            }
        }

        return $instance;
    }
}
