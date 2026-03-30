<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\WatermarkEnabled;
use App\Actions\Attributes\WatermarkMode;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Auth;

class WatermarkDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        // Check if watermarking is enabled (default: true)
        if (! $this->shouldApplyWatermark()) {
            return $result;
        }

        return $this->applyWatermark($result);
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    protected function applyWatermark(mixed $result): mixed
    {
        $watermark = $this->getWatermarkData();
        $appendMode = $this->getWatermarkMode() === 'append';

        if (is_array($result)) {
            if ($appendMode) {
                $result['_watermark'] = $watermark;

                return $result;
            }

            // Wrap mode: return new structure
            return [
                'data' => $result,
                '_watermark' => $watermark,
            ];
        }

        if (is_object($result)) {
            if ($appendMode) {
                // Always try to add watermark as property first (preserves object type)
                // Use reflection to set protected/private properties if needed
                try {
                    $reflection = new \ReflectionClass($result);
                    if ($reflection->hasProperty('_watermark')) {
                        $property = $reflection->getProperty('_watermark');
                        $property->setAccessible(true);
                        $property->setValue($result, $watermark);
                    } else {
                        // Property doesn't exist, try dynamic property (PHP 8.2+)
                        $result->_watermark = $watermark;
                    }
                } catch (\ReflectionException $e) {
                    // Fallback: try direct assignment
                    $result->_watermark = $watermark;
                }

                return $result;
            }

            // Wrap mode: wrap in array
            return [
                'data' => $result,
                '_watermark' => $watermark,
            ];
        }

        // For other types, always wrap
        return [
            'data' => $result,
            '_watermark' => $watermark,
        ];
    }

    protected function getWatermarkMode(): string
    {
        // Check for attribute first
        $mode = $this->getAttributeValue(WatermarkMode::class);
        if ($mode !== null) {
            return $mode;
        }

        // Fall back to method
        // 'append' = modify result in place (default)
        // 'wrap' = wrap result in new structure
        return $this->fromActionMethod('getWatermarkMode', [], 'append');
    }

    protected function shouldApplyWatermark(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(WatermarkEnabled::class);
        if ($enabled !== null) {
            return $enabled;
        }

        // Fall back to method
        // Allow actions to opt-out of watermarking
        return $this->fromActionMethod('shouldApplyWatermark', [], true);
    }

    protected function getWatermarkData(): array
    {
        // Get the original action class (unwrap decorators)
        $originalAction = $this->getOriginalAction();

        $default = array_filter([
            'action' => get_class($originalAction),
            'executed_at' => now()->toIso8601String(),
            'executed_by' => Auth::check() ? Auth::id() : null,
            'request_id' => request()->header('X-Request-ID'),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'route' => optional(request()->route())->getName(),
            'method' => request()->method(),
            'url' => request()->fullUrl(),
            'app_env' => config('app.env'),
            'app_version' => config('app.version'), // if defined
        ], fn ($value) => ! is_null($value));

        return $this->fromActionMethod('getWatermarkData', [], $default);
    }

    protected function getAttributeValue(string $attributeClass): string|bool|null
    {
        // Unwrap decorators to get the original action
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof WatermarkMode) {
                    return $attribute->mode;
                }
                if ($attribute instanceof WatermarkEnabled) {
                    return $attribute->enabled;
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

    public static function removeWatermark(mixed $result): mixed
    {
        if (is_array($result) && isset($result['_watermark'])) {
            unset($result['_watermark']);

            return $result;
        }

        if (is_object($result) && property_exists($result, '_watermark')) {
            unset($result->_watermark);
        }

        return $result;
    }
}
