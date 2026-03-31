<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\WebhookHeaders;
use App\Actions\Attributes\WebhookMethod;
use App\Actions\Attributes\WebhookUrl;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Http;

class WebhookDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        $this->sendWebhook($result, $arguments);

        return $result;
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    protected function sendWebhook($result, array $arguments): void
    {
        $url = $this->getWebhookUrl();

        if (! $url) {
            return;
        }

        $payload = $this->getWebhookPayload($result, $arguments);
        $headers = $this->getWebhookHeaders();
        $method = $this->getWebhookMethod();

        try {
            $response = Http::withHeaders($headers)
                ->{$method}($url, $payload);

            // Call success callback if action has it
            if ($this->hasMethod('onWebhookSuccess')) {
                $this->callMethod('onWebhookSuccess', [$response, $payload]);
            }
        } catch (\Throwable $e) {
            // Log webhook failure but don't fail the action
            if ($this->hasMethod('onWebhookFailure')) {
                $this->callMethod('onWebhookFailure', [$e, $payload]);
            }
        }
    }

    protected function getWebhookUrl(): ?string
    {
        // Check for attribute first
        $url = $this->getAttributeValue(WebhookUrl::class);
        if ($url !== null) {
            return $url;
        }

        // Fall back to method or property
        return $this->fromActionMethodOrProperty('getWebhookUrl', 'webhookUrl');
    }

    protected function getWebhookPayload($result, array $arguments): array
    {
        return $this->fromActionMethod('getWebhookPayload', [$result, $arguments], [
            'action' => get_class($this->action),
            'result' => $result,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    protected function getWebhookHeaders(): array
    {
        // Check for attribute first
        $headers = $this->getAttributeValue(WebhookHeaders::class);
        if ($headers !== null) {
            return $headers;
        }

        // Fall back to method
        return $this->fromActionMethod('getWebhookHeaders', [], [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-Actions/1.0',
        ]);
    }

    protected function getWebhookMethod(): string
    {
        // Check for attribute first
        $method = $this->getAttributeValue(WebhookMethod::class);
        if ($method !== null) {
            return $method;
        }

        // Fall back to method
        return $this->fromActionMethod('getWebhookMethod', [], 'post');
    }

    protected function getAttributeValue(string $attributeClass): string|array|null
    {
        // Unwrap decorators to get the original action
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof WebhookUrl) {
                    return $attribute->url;
                }
                if ($attribute instanceof WebhookMethod) {
                    return $attribute->method;
                }
                if ($attribute instanceof WebhookHeaders) {
                    return $attribute->headers;
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
