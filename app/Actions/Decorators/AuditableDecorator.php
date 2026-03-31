<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Decorator that automatically logs audit trails for actions.
 *
 * This decorator captures before/after state and records comprehensive audit logs
 * including user, action, arguments, result, IP address, user agent, and timestamp.
 */
class AuditableDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Make the decorator invokable when used as a controller.
     */
    public function __invoke(mixed ...$arguments): mixed
    {
        if ($this->hasMethod('asController')) {
            return $this->handleAsController();
        }

        return $this->handle(...$arguments);
    }

    protected function handleAsController(): mixed
    {
        $beforeState = $this->captureBeforeState([]);

        try {
            $result = $this->callMethod('asController');
            $afterState = $this->captureAfterState($result, []);

            $this->recordAudit([], $result, $beforeState, $afterState);

            return $result;
        } catch (\Throwable $e) {
            $this->recordAudit([], null, $beforeState, null, $e);

            throw $e;
        }
    }

    public function handle(...$arguments)
    {
        $beforeState = $this->captureBeforeState($arguments);

        try {
            $result = $this->callMethod('handle', $arguments);
            $afterState = $this->captureAfterState($result, $arguments);

            $this->recordAudit($arguments, $result, $beforeState, $afterState);

            return $result;
        } catch (\Throwable $e) {
            // Record audit even on failure
            $this->recordAudit($arguments, null, $beforeState, null, $e);

            throw $e;
        }
    }

    protected function captureBeforeState(array $arguments): ?array
    {
        if ($this->hasMethod('getBeforeState')) {
            return $this->callMethod('getBeforeState', [$arguments]);
        }

        // Try to capture state from first argument if it's a model
        if (isset($arguments[0]) && is_object($arguments[0]) && method_exists($arguments[0], 'getAttributes')) {
            return $arguments[0]->getAttributes();
        }

        return null;
    }

    protected function captureAfterState($result, array $arguments): ?array
    {
        if ($this->hasMethod('getAfterState')) {
            return $this->callMethod('getAfterState', [$result, $arguments]);
        }

        if (is_object($result) && method_exists($result, 'getAttributes')) {
            return $result->getAttributes();
        }

        return null;
    }

    protected function recordAudit(array $arguments, $result, ?array $beforeState, ?array $afterState, ?\Throwable $exception = null): void
    {
        $auditData = [
            'user_id' => Auth::id(),
            'action' => get_class($this->action),
            'action_name' => class_basename($this->action),
            'arguments' => $this->sanitizeAuditArguments($arguments),
            'result' => $this->sanitizeAuditResult($result),
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'timestamp' => now(),
            'exception' => $exception ? $exception->getMessage() : null,
        ];

        if ($this->hasMethod('getAuditData')) {
            $customData = $this->callMethod('getAuditData', [$result, $arguments]);
            $auditData = array_merge($auditData, $customData);
        }

        $this->storeAudit($auditData);
    }

    protected function storeAudit(array $auditData): void
    {
        if ($this->hasMethod('storeAuditRecord')) {
            $this->callMethod('storeAuditRecord', [$auditData]);

            return;
        }

        // Default: store in audits table if it exists
        if (DB::getSchemaBuilder()->hasTable('audits')) {
            DB::table('audits')->insert($auditData);
        }
    }

    protected function sanitizeAuditArguments(array $arguments): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'ssn'];

        return array_map(function ($arg) use ($sensitive) {
            if (is_object($arg)) {
                return get_class($arg).' (id: '.($arg->id ?? 'N/A').')';
            }

            if (is_array($arg)) {
                foreach ($arg as $key => $value) {
                    if (in_array(strtolower($key), array_map('strtolower', $sensitive))) {
                        $arg[$key] = '***REDACTED***';
                    }
                }
            }

            return $arg;
        }, $arguments);
    }

    protected function sanitizeAuditResult($result): mixed
    {
        if (is_object($result)) {
            return get_class($result).' (id: '.($result->id ?? 'N/A').')';
        }

        return $result;
    }
}
