<?php

declare(strict_types=1);

namespace App\Services\Deploy;

final class DeploymentValueRedactor
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redactContext(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $redacted[$key] = $this->redactValue((string) $key, $value);
        }

        return $redacted;
    }

    public function redactMessage(string $message): string
    {
        $message = preg_replace('/(APP_KEY=)([^\s]+)/', '$1[REDACTED]', $message) ?: $message;
        $message = preg_replace('/((?:token|secret|password|passwd|private[_-]?key|access[_-]?key)["\']?\s*[:=]\s*["\']?)([^"\',\s]+)/i', '$1[REDACTED]', $message) ?: $message;

        return $message;
    }

    private function redactValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            $nested = [];

            foreach ($value as $nestedKey => $nestedValue) {
                $nested[(string) $nestedKey] = $this->redactValue((string) $nestedKey, $nestedValue);
            }

            return $nested;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $string = (string) $value;

        if ($this->looksSensitiveKey($key) || $this->looksSensitiveValue($string)) {
            return '[REDACTED]';
        }

        return $this->redactMessage($string);
    }

    private function looksSensitiveKey(string $key): bool
    {
        return preg_match('/secret|token|password|passwd|private|credential|api[_-]?key|access[_-]?key|authorization/i', $key) === 1;
    }

    private function looksSensitiveValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 'base64:')) {
            return true;
        }

        if (str_contains($value, 'BEGIN ') && str_contains($value, 'PRIVATE KEY')) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9+\/=_-]{32,}$/', $value) === 1;
    }
}
