<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\TransactionAttempts;
use App\Actions\Attributes\TransactionConnection;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\DB;

/**
 * Transaction Decorator
 *
 * Automatically wraps action execution in a database transaction.
 * This decorator ensures that all database operations within an action
 * are executed atomically - either all succeed or all are rolled back.
 *
 * Features:
 * - Automatic transaction wrapping
 * - Support for custom database connections
 * - Configurable transaction attempts (for deadlock retries)
 * - Transaction metadata in results
 * - Seamless integration with other decorators
 *
 * How it works:
 * 1. When an action uses AsTransaction, TransactionDesignPattern recognizes it
 * 2. ActionManager wraps the action with TransactionDecorator
 * 3. When handle() is called, the decorator:
 *    - Determines the database connection (if specified)
 *    - Gets the number of transaction attempts
 *    - Wraps the action's handle() in DB::transaction()
 *    - Adds transaction metadata to the result
 *    - Returns the result (or rolls back on exception)
 *
 * Transaction Metadata:
 * The result will include a `_transaction` property with:
 * - `used`: Always true (indicates transaction was used)
 * - `connection`: The database connection name ('default' if not specified)
 * - `attempts`: The number of transaction attempts configured
 *
 * Example:
 * $result = CreateTag::run($team, ['name' => 'New Tag']);
 * // $result->_transaction = [
 * //     'used' => true,
 * //     'connection' => 'default',
 * //     'attempts' => 1,
 * // ];
 */
class TransactionDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action within a database transaction.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $connection = $this->getTransactionConnection();
        $attempts = $this->getTransactionAttempts();

        $callback = function () use ($arguments) {
            return $this->action->handle(...$arguments);
        };

        $result = null;
        if ($connection) {
            $result = DB::connection($connection)->transaction($callback, $attempts);
        } else {
            $result = DB::transaction($callback, $attempts);
        }

        // Add transaction metadata to the result
        return $this->addTransactionMetadata($result, $connection, $attempts);
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
     * Get the database connection name for the transaction.
     *
     * Checks for:
     * 1. #[TransactionConnection] attribute on the action
     * 2. getTransactionConnection() method on the action
     * 3. transactionConnection property on the action
     *
     * @return string|null The connection name, or null to use default
     */
    protected function getTransactionConnection(): ?string
    {
        // Check for attribute first
        $connection = $this->getAttributeValue(TransactionConnection::class);
        if ($connection !== null) {
            return $connection;
        }

        // Fall back to method or property
        return $this->fromActionMethodOrProperty(
            'getTransactionConnection',
            'transactionConnection',
            null
        );
    }

    /**
     * Get the number of transaction attempts.
     *
     * Useful for handling deadlocks and retries. Defaults to 1.
     *
     * Checks for:
     * 1. #[TransactionAttempts] attribute on the action
     * 2. getTransactionAttempts() method on the action
     * 3. transactionAttempts property on the action
     *
     * @return int Number of attempts (default: 1)
     */
    protected function getTransactionAttempts(): int
    {
        // Check for attribute first
        $attempts = $this->getAttributeValue(TransactionAttempts::class);
        if ($attempts !== null) {
            return $attempts;
        }

        // Fall back to method or property
        return $this->fromActionMethodOrProperty(
            'getTransactionAttempts',
            'transactionAttempts',
            1
        );
    }

    /**
     * Get attribute value from the original action.
     *
     * @param  string  $attributeClass  The attribute class name
     * @return mixed The attribute value, or null if not found
     */
    protected function getAttributeValue(string $attributeClass): mixed
    {
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof TransactionConnection) {
                    return $attribute->connection;
                }
                if ($attribute instanceof TransactionAttempts) {
                    return $attribute->attempts;
                }
            }
        } catch (\ReflectionException $e) {
            // Attribute not found or can't be read
        }

        return null;
    }

    /**
     * Get the original action (unwrap decorators).
     *
     * @return mixed
     */
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

    /**
     * Add transaction metadata to the result.
     *
     * Adds a `_transaction` property to the result indicating:
     * - Whether a transaction was used (always true for this decorator)
     * - Which database connection was used
     * - How many attempts were configured
     *
     * @param  mixed  $result  The action result
     * @param  string|null  $connection  The database connection name
     * @param  int  $attempts  The number of transaction attempts
     * @return mixed The result with transaction metadata added
     */
    protected function addTransactionMetadata(mixed $result, ?string $connection, int $attempts): mixed
    {
        $metadata = [
            'used' => true,
            'connection' => $connection ?? 'default',
            'attempts' => $attempts,
        ];

        if (is_array($result)) {
            $result['_transaction'] = $metadata;

            return $result;
        }

        if (is_object($result)) {
            // Try to add transaction metadata as property (preserves object type)
            try {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('_transaction')) {
                    $property = $reflection->getProperty('_transaction');
                    $property->setAccessible(true);
                    $property->setValue($result, $metadata);
                } else {
                    // Property doesn't exist, use dynamic property (works for Eloquent models)
                    $result->_transaction = $metadata;
                }
            } catch (\ReflectionException $e) {
                // Fallback: try direct assignment
                $result->_transaction = $metadata;
            }

            return $result;
        }

        // For other types, wrap in array with metadata
        return [
            'data' => $result,
            '_transaction' => $metadata,
        ];
    }
}
