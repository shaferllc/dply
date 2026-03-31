<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

class EventDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle($event)
    {
        // Try asEventListener() method first
        if ($this->hasMethod('asEventListener')) {
            return $this->callMethod('asEventListener', [$event]);
        }

        // Try handle() method
        if ($this->hasMethod('handle')) {
            return $this->callMethod('handle', [$event]);
        }

        // Try __invoke() method
        if ($this->hasMethod('__invoke')) {
            return $this->callMethod('__invoke', [$event]);
        }

        return null;
    }

    public function __invoke($event)
    {
        return $this->handle($event);
    }
}
