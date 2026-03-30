<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\UpdateDispatchEvent;
use App\Actions\Attributes\UpdateEventClass;
use App\Actions\Attributes\UpdateTrackChanges;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class UpdateDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        // Track what was updated if result is a model
        if (is_object($result) && method_exists($result, 'getChanges')) {
            $this->trackChanges($result);
        }

        // Dispatch update event if enabled
        if ($this->shouldDispatchEvent()) {
            $this->dispatchUpdateEvent($result, $arguments);
        }

        return $result;
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    protected function trackChanges($model): void
    {
        if (! $this->shouldTrackChanges()) {
            return;
        }

        $changes = $model->getChanges();
        if (empty($changes)) {
            return;
        }

        // Store changes in model for later access
        if (method_exists($model, 'setAttribute')) {
            $model->setAttribute('_updated_fields', array_keys($changes));
            $model->setAttribute('_update_metadata', [
                'changed_at' => now()->toIso8601String(),
                'changed_by' => Auth::id(),
            ]);
        }
    }

    protected function dispatchUpdateEvent($result, array $arguments): void
    {
        $eventClass = $this->getUpdateEventClass();
        if ($eventClass && class_exists($eventClass)) {
            Event::dispatch(new $eventClass($result, $arguments));
        }
    }

    protected function shouldTrackChanges(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(UpdateTrackChanges::class);
        if ($enabled !== null) {
            return $enabled;
        }

        // Fall back to method
        return $this->fromActionMethod('shouldTrackChanges', [], true);
    }

    protected function shouldDispatchEvent(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(UpdateDispatchEvent::class);
        if ($enabled !== null) {
            return $enabled;
        }

        // Fall back to method
        return $this->fromActionMethod('shouldDispatchEvent', [], false);
    }

    protected function getUpdateEventClass(): ?string
    {
        // Check for attribute first
        $eventClass = $this->getAttributeValue(UpdateEventClass::class);
        if ($eventClass !== null) {
            return $eventClass;
        }

        // Fall back to method
        return $this->fromActionMethod('getUpdateEventClass', [], null);
    }

    protected function getAttributeValue(string $attributeClass): bool|string|null
    {
        // Unwrap decorators to get the original action
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof UpdateTrackChanges) {
                    return $attribute->enabled;
                }
                if ($attribute instanceof UpdateDispatchEvent) {
                    return $attribute->enabled;
                }
                if ($attribute instanceof UpdateEventClass) {
                    return $attribute->eventClass;
                }
            }
        } catch (\ReflectionException $e) {
            // Attribute not found or can't be read
        }

        return null;
    }

    protected function getOriginalAction()
    {
        $action = $this->action;

        // Unwrap decorators to get the original action
        while (str_starts_with(get_class($action), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($action);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $action = $property->getValue($action);
            } else {
                break;
            }
        }

        return $action;
    }
}
