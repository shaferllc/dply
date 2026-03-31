<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorator that handles API versioning for actions.
 *
 * This decorator automatically detects the API version from various sources
 * (headers, route parameters, query parameters) and makes it available to actions.
 */
class ApiVersionDecorator
{
    use DecorateActions;

    protected ?string $apiVersion = null;

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setApiVersionDecorator')) {
            $action->setApiVersionDecorator($this);
        } elseif (property_exists($action, '_apiVersionDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_apiVersionDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        // Detect and set API version before execution
        $this->apiVersion = $this->detectApiVersion();
        $this->setApiVersionOnAction($this->apiVersion);

        // Execute the action
        return $this->callMethod('handle', $arguments);
    }

    public function getApiVersion(): string
    {
        if ($this->apiVersion === null) {
            $this->apiVersion = $this->detectApiVersion();
        }

        return $this->apiVersion;
    }

    public function setApiVersion(string $version): self
    {
        $this->apiVersion = $version;
        $this->setApiVersionOnAction($version);

        return $this;
    }

    protected function setApiVersionOnAction(string $version): void
    {
        if (method_exists($this->action, 'setApiVersion')) {
            $this->action->setApiVersion($version);
        } elseif (property_exists($this->action, 'apiVersion')) {
            $reflection = new \ReflectionClass($this->action);
            $property = $reflection->getProperty('apiVersion');
            $property->setAccessible(true);
            $property->setValue($this->action, $version);
        }
    }

    protected function detectApiVersion(): string
    {
        if ($this->hasMethod('detectApiVersion')) {
            return $this->callMethod('detectApiVersion');
        }

        // Try header first
        $version = request()->header('API-Version');

        if ($version) {
            return $version;
        }

        // Try Accept header
        $accept = request()->header('Accept');

        if ($accept && preg_match('/version=([^;,\s]+)/', $accept, $matches)) {
            return $matches[1];
        }

        // Try route parameter
        $version = request()->route('version');

        if ($version) {
            return $version;
        }

        // Try query parameter
        $version = request()->query('version');

        if ($version) {
            return $version;
        }

        // Default version
        return $this->getDefaultApiVersion();
    }

    protected function getDefaultApiVersion(): string
    {
        if ($this->hasMethod('getDefaultApiVersion')) {
            return $this->callMethod('getDefaultApiVersion');
        }

        if ($this->hasProperty('defaultApiVersion')) {
            return $this->getProperty('defaultApiVersion');
        }

        return 'v1';
    }
}
