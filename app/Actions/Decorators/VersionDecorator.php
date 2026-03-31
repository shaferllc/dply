<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\VersionDefault;
use App\Actions\Attributes\VersionHeader;
use App\Actions\Concerns\DecorateActions;

class VersionDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        // Determine and set version before calling action
        $version = $this->getVersion();
        $this->setVersionOnAction($version);

        // Execute the action with the version set
        $result = $this->action->handle(...$arguments);

        // Append version to result model
        return $this->appendVersionToResult($result, $version);
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    protected function getVersion(): string
    {
        // First check if action has version property set (highest priority)
        // Use reflection to access protected property
        try {
            $reflection = new \ReflectionClass($this->action);
            if ($reflection->hasProperty('version')) {
                $property = $reflection->getProperty('version');
                $property->setAccessible(true);
                $version = $property->getValue($this->action);
                if ($version !== null) {
                    return $version;
                }
            }
        } catch (\ReflectionException $e) {
            // Property doesn't exist or can't be accessed
        }

        // Then check if action has custom getVersion method
        if ($this->hasMethod('getVersion')) {
            return $this->callMethod('getVersion');
        }

        // Get default version and header name from attributes
        $defaultVersion = $this->getAttributeValue(VersionDefault::class) ?? 'v1';
        $headerName = $this->getAttributeValue(VersionHeader::class) ?? 'API-Version';

        // Default: get from request header or use default from attribute
        return request()->header($headerName, $defaultVersion);
    }

    protected function getAttributeValue(string $attributeClass): ?string
    {
        // Unwrap decorators to get the original action
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof VersionDefault) {
                    return $attribute->version;
                }
                if ($attribute instanceof VersionHeader) {
                    return $attribute->header;
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

    protected function setVersionOnAction(string $version): void
    {
        // Set version on action if it has setVersion method
        if ($this->hasMethod('setVersion')) {
            $this->callMethod('setVersion', [$version]);
        } elseif ($this->hasProperty('version')) {
            // Try to set property directly using reflection
            try {
                $reflection = new \ReflectionClass($this->action);
                if ($reflection->hasProperty('version')) {
                    $property = $reflection->getProperty('version');
                    $property->setAccessible(true);
                    $property->setValue($this->action, $version);
                }
            } catch (\ReflectionException $e) {
                // If we can't set it, that's okay - action might handle version differently
            }
        }
    }

    protected function appendVersionToResult(mixed $result, string $version): mixed
    {
        // Add version information to the result model
        if (is_object($result)) {
            try {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('_version')) {
                    $property = $reflection->getProperty('_version');
                    $property->setAccessible(true);
                    $property->setValue($result, $version);
                } else {
                    // Use dynamic property (PHP 8.2+)
                    $result->_version = $version;
                }
            } catch (\ReflectionException $e) {
                // Fallback: try direct assignment
                $result->_version = $version;
            }
        } elseif (is_array($result)) {
            // For arrays, add version key
            $result['_version'] = $version;
        }

        return $result;
    }
}
