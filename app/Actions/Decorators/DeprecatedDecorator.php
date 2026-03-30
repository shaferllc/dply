<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Log;

/**
 * Deprecated Decorator
 *
 * Warns when deprecated actions are used by logging deprecation warnings
 * and triggering PHP deprecation notices in development environments.
 *
 * Features:
 * - Logs deprecation warnings when action is called
 * - Triggers PHP E_USER_DEPRECATED in local/testing environments
 * - Configurable deprecation messages
 * - Removal version tracking
 * - Stack trace logging for debugging
 *
 * How it works:
 * 1. When an action uses AsDeprecated, DeprecatedDesignPattern recognizes it
 * 2. ActionManager wraps the action with DeprecatedDecorator
 * 3. When handle() is called, the decorator:
 *    - Logs a deprecation warning with full context
 *    - Triggers PHP deprecation notice in dev/test
 *    - Executes the action normally
 *    - Returns the result
 */
class DeprecatedDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with deprecation warning.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $this->logDeprecationWarning();

        return $this->callMethod('handle', $arguments);
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Log deprecation warning.
     */
    protected function logDeprecationWarning(): void
    {
        $message = $this->getDeprecationMessage();
        $removalVersion = $this->getRemovalVersion();

        $fullMessage = sprintf(
            'Action %s is deprecated. %s %s',
            get_class($this->action),
            $message,
            $removalVersion ? "Will be removed in v{$removalVersion}." : ''
        );

        Log::warning($fullMessage, [
            'action' => get_class($this->action),
            'removal_version' => $removalVersion,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ]);

        if (app()->environment(['local', 'testing'])) {
            trigger_error($fullMessage, E_USER_DEPRECATED);
        }
    }

    /**
     * Get the deprecation message.
     */
    protected function getDeprecationMessage(): string
    {
        if ($this->hasMethod('getDeprecationMessage')) {
            return $this->callMethod('getDeprecationMessage');
        }

        return 'This action is deprecated and should not be used.';
    }

    /**
     * Get the removal version.
     */
    protected function getRemovalVersion(): ?string
    {
        if ($this->hasMethod('getRemovalVersion')) {
            return $this->callMethod('getRemovalVersion');
        }

        return null;
    }
}
