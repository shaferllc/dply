<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\Transformations;
use App\Actions\Attributes\TransformMode;
use App\Actions\Concerns\DecorateActions;

class TransformerDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        // Apply transformation if enabled and result is transformable
        if ($this->shouldTransform() && $this->isTransformable($result)) {
            return $this->transform($result);
        }

        return $result;
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    protected function transform(mixed $data): mixed
    {
        $transformations = $this->getTransformations();

        if (empty($transformations)) {
            return $data;
        }

        if (is_array($data)) {
            return $this->transformArray($data, $transformations);
        }

        if (is_object($data)) {
            // For Eloquent models and objects with toArray, preserve object type
            if (method_exists($data, 'toArray')) {
                $originalArray = $data->toArray();
                $transformed = $this->transformArray($originalArray, $transformations);

                // Check if we should nest under _transformed or apply directly
                if ($this->shouldNestTransformed()) {
                    // Store transformed data under _transformed property
                    $this->setTransformedProperty($data, $transformed);
                } else {
                    // Apply transformations directly to object properties
                    $this->applyTransformationsToObject($data, $transformed, $originalArray, $transformations);
                }

                // Return the same object
                return $data;
            }

            // For objects without toArray, try to transform properties directly
            return $this->transformObject($data, $transformations);
        }

        return $data;
    }

    protected function shouldNestTransformed(): bool
    {
        // Check for attribute first
        $mode = $this->getAttributeValue(TransformMode::class);
        if ($mode !== null) {
            return $mode === 'nested';
        }

        // Fall back to method
        return $this->fromActionMethod('shouldNestTransformed', [], true);
    }

    protected function setTransformedProperty($object, array $transformed): void
    {
        try {
            $reflection = new \ReflectionClass($object);
            if ($reflection->hasProperty('_transformed')) {
                $property = $reflection->getProperty('_transformed');
                $property->setAccessible(true);
                $property->setValue($object, $transformed);
            } else {
                // Use dynamic property (works for Eloquent models)
                $object->_transformed = $transformed;
            }
        } catch (\ReflectionException $e) {
            // Fallback: try dynamic property
            $object->_transformed = $transformed;
        }
    }

    protected function applyTransformationsToObject($object, array $transformed, array $original, array $transformations): void
    {
        // Apply transformations: set new properties with transformed keys/values
        foreach ($transformations as $originalKey => $transformation) {
            if (! isset($original[$originalKey])) {
                continue; // Key doesn't exist in original
            }

            $originalValue = $original[$originalKey];

            if (is_string($transformation)) {
                // Key rename: set new property with transformed key
                $newKey = $transformation;
                $this->setObjectProperty($object, $newKey, $originalValue);
            } elseif (is_callable($transformation)) {
                // Value transformation: transform the value
                $transformedValue = $transformation($originalValue, $originalKey, $original);
                $this->setObjectProperty($object, $originalKey, $transformedValue);
            } elseif (is_array($transformation) && is_array($originalValue)) {
                // Nested transformation: recursively transform
                $nestedTransformed = $this->transformArray($originalValue, $transformation);
                $this->setObjectProperty($object, $originalKey, $nestedTransformed);
            }
        }

        // Also set any new keys that appeared in transformed (from nested transformations)
        foreach ($transformed as $key => $value) {
            if (! isset($original[$key]) && ! isset($transformations[$key])) {
                // New key from nested transformation
                $this->setObjectProperty($object, $key, $value);
            }
        }
    }

    protected function setObjectProperty($object, string $key, $value): void
    {
        try {
            $reflection = new \ReflectionClass($object);
            if ($reflection->hasProperty($key)) {
                $property = $reflection->getProperty($key);
                $property->setAccessible(true);
                $property->setValue($object, $value);
            } else {
                // Use dynamic property (works for Eloquent models)
                $object->{$key} = $value;
            }
        } catch (\ReflectionException $e) {
            // Fallback: try dynamic property
            $object->{$key} = $value;
        }
    }

    protected function transformObject($object, array $transformations): mixed
    {
        // Convert object to array for transformation
        $data = [];
        $reflection = new \ReflectionClass($object);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $key = $property->getName();
            $data[$key] = $property->getValue($object);
        }

        // Transform the array
        $transformed = $this->transformArray($data, $transformations);

        // Store transformed data under _transformed property
        $this->setTransformedProperty($object, $transformed);

        return $object;
    }

    protected function transformArray(array $data, array $transformations): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (isset($transformations[$key])) {
                $transformation = $transformations[$key];

                if (is_string($transformation)) {
                    // Simple key rename
                    $result[$transformation] = $value;
                } elseif (is_callable($transformation)) {
                    // Callable transformation
                    $newKey = $this->getTransformedKey($key, $transformations);
                    $result[$newKey] = $transformation($value, $key, $data);
                } elseif (is_array($transformation)) {
                    // Nested transformation
                    $newKey = $key;
                    $result[$newKey] = is_array($value) ? $this->transformArray($value, $transformation) : $value;
                } else {
                    $result[$key] = $value;
                }
            } else {
                // Recursively transform nested arrays
                $result[$key] = is_array($value) ? $this->transformArray($value, $transformations) : $value;
            }
        }

        return $result;
    }

    protected function getTransformedKey(string $originalKey, array $transformations): string
    {
        if (isset($transformations[$originalKey]) && is_string($transformations[$originalKey])) {
            return $transformations[$originalKey];
        }

        return $originalKey;
    }

    protected function shouldTransform(): bool
    {
        return $this->fromActionMethod('shouldTransform', [], true);
    }

    protected function isTransformable(mixed $data): bool
    {
        return is_array($data) || (is_object($data) && method_exists($data, 'toArray'));
    }

    protected function getTransformations(): array
    {
        // Check for attribute first
        $transformations = $this->getAttributeValue(Transformations::class);
        if ($transformations !== null) {
            return $transformations;
        }

        // Fall back to method
        return $this->fromActionMethod('getTransformations', [], []);
    }

    protected function getAttributeValue(string $attributeClass): array|string|null
    {
        // Unwrap decorators to get the original action
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof Transformations) {
                    return $attribute->transformations;
                }
                if ($attribute instanceof TransformMode) {
                    return $attribute->mode;
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
