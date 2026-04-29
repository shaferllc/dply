<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\TransactionAttempts;
use App\Actions\Attributes\TransactionConnection;
use App\Actions\Decorators\TransactionDecorator;
use App\Actions\DesignPatterns\TransactionDesignPattern;

/**
 * Automatically wraps action execution in a database transaction.
 *
 * Uses the decorator pattern to automatically wrap actions and ensure
 * all database operations are executed within a transaction. The
 * TransactionDecorator intercepts handle() calls and wraps them in
 * DB::transaction().
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
 * Benefits:
 * - Atomic operations: All database changes succeed or fail together
 * - Automatic rollback on exceptions
 * - Support for custom database connections
 * - Configurable retry attempts for deadlock handling
 * - Transaction metadata in results
 * - Seamless integration with other decorators
 *
 * @example
 * // ============================================
 * // Example 1: Basic Usage (Default Behavior)
 * // ============================================
 * class CreateOrderWithItems extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         $order = Order::create([
 *             'user_id' => $user->id,
 *             'total' => 0,
 *         ]);
 *
 *         foreach ($items as $item) {
 *             OrderItem::create([
 *                 'order_id' => $order->id,
 *                 'product_id' => $item['product_id'],
 *                 'quantity' => $item['quantity'],
 *             ]);
 *         }
 *
 *         // If any step fails, everything rolls back automatically
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = CreateOrderWithItems::run($user, $items);
 * // All database operations are automatically wrapped in a transaction
 * // Transaction metadata: $order->_transaction = ['used' => true, 'connection' => 'default', 'attempts' => 1]
 * @example
 * // ============================================
 * // Example 2: Custom Database Connection (Using Attribute)
 * // ============================================
 * use App\Actions\Attributes\TransactionConnection;
 *
 * #[TransactionConnection('mysql')]
 * class CreateOrderWithItems extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         $order = Order::create([...]);
 *         // All operations use 'mysql' connection
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = CreateOrderWithItems::run($user, $items);
 * // Transaction uses 'mysql' connection
 * // $order->_transaction = ['used' => true, 'connection' => 'mysql', 'attempts' => 1]
 * @example
 * // ============================================
 * // Example 3: Custom Database Connection (Using Method)
 * // ============================================
 * class CreateOrderWithItems extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         $order = Order::create([...]);
 *         return $order;
 *     }
 *
 *     // Specify a different database connection
 *     public function getTransactionConnection(): string
 *     {
 *         // Can be dynamic based on context
 *         return config('database.default'); // or 'pgsql', etc.
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Transaction Attempts for Deadlock Retries (Using Attribute)
 * // ============================================
 * use App\Actions\Attributes\TransactionAttempts;
 *
 * #[TransactionAttempts(3)] // Retry up to 3 times on deadlock
 * class CreateOrderWithItems extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         // High-concurrency operation that might deadlock
 *         $order = Order::create([...]);
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = CreateOrderWithItems::run($user, $items);
 * // If deadlock occurs, transaction will retry up to 3 times
 * // $order->_transaction = ['used' => true, 'connection' => 'default', 'attempts' => 3]
 * @example
 * // ============================================
 * // Example 5: Transaction Attempts (Using Method)
 * // ============================================
 * class CreateOrderWithItems extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         $order = Order::create([...]);
 *         return $order;
 *     }
 *
 *     // Retry up to 3 times on deadlock
 *     public function getTransactionAttempts(): int
 *     {
 *         // Can be dynamic based on context
 *         return app()->environment('production') ? 3 : 1;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Using Properties Instead of Methods
 * // ============================================
 * class CreateOrderWithItems extends Actions
 * {
 *     use AsTransaction;
 *
 *     protected string $transactionConnection = 'mysql';
 *     protected int $transactionAttempts = 3;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         $order = Order::create([...]);
 *         return $order;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\TransactionAttempts;
 *
 * #[TransactionAttempts(1)]
 * class CreateTag extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(Team $team, array $formData): Tag
 *     {
 *         // All database operations are automatically wrapped in a transaction
 *         $tag = Tag::findOrCreate(['slug' => $tagSlug], ['name' => $tagName]);
 *         $team->tags()->attach($tag);
 *
 *         // If Tag::findOrCreate() or $team->tags()->attach() fails,
 *         // the entire operation is rolled back
 *         return $tag;
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 * // Transaction metadata: $tag->_transaction = [
 * //     'used' => true,
 * //     'connection' => 'default',
 * //     'attempts' => 1,
 * // ]
 * @example
 * // ============================================
 * // Example 8: Complex Multi-Step Transaction
 * // ============================================
 * #[TransactionAttempts(2)]
 * class ProcessPayment extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(Order $order, PaymentData $paymentData): Payment
 *     {
 *         // Step 1: Create payment record
 *         $payment = Payment::create([
 *             'order_id' => $order->id,
 *             'amount' => $paymentData->amount,
 *             'status' => 'processing',
 *         ]);
 *
 *         // Step 2: Update order status
 *         $order->update(['status' => 'paid']);
 *
 *         // Step 3: Deduct inventory
 *         foreach ($order->items as $item) {
 *             $item->product->decrement('stock', $item->quantity);
 *         }
 *
 *         // Step 4: Create audit log
 *         AuditLog::create([
 *             'action' => 'payment_processed',
 *             'order_id' => $order->id,
 *             'payment_id' => $payment->id,
 *         ]);
 *
 *         // If ANY step fails, ALL changes are rolled back automatically
 *         return $payment;
 *     }
 * }
 *
 * // Usage:
 * try {
 *     $payment = ProcessPayment::run($order, $paymentData);
 *     // All steps completed successfully, transaction committed
 * } catch (\Exception $e) {
 *     // Any exception causes automatic rollback
 *     // No partial data saved
 * }
 * @example
 * // ============================================
 * // Example 9: Accessing Transaction Metadata
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $data): Order
 *     {
 *         return Order::create($data);
 *     }
 * }
 *
 * // Usage:
 * $order = CreateOrder::run($user, $data);
 *
 * // Access transaction metadata:
 * $transactionInfo = $order->_transaction;
 * // [
 * //     'used' => true,
 * //     'connection' => 'default',
 * //     'attempts' => 1,
 * // ]
 *
 * // Check if transaction was used:
 * if ($order->_transaction['used']) {
 *     // Transaction was used
 * }
 *
 * // Check which connection was used:
 * $connection = $order->_transaction['connection'];
 * @example
 * // ============================================
 * // Example 10: Dynamic Connection Based on Context
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsTransaction;
 *
 *     public function handle(User $user, array $data): Order
 *     {
 *         return Order::create($data);
 *     }
 *
 *     // Dynamic connection based on user's tenant
 *     public function getTransactionConnection(): string
 *     {
 *         $tenant = auth()->user()?->tenant;
 *
 *         return $tenant?->database_connection ?? 'default';
 *     }
 *
 *     // Dynamic attempts based on environment
 *     public function getTransactionAttempts(): int
 *     {
 *         return app()->environment('production') ? 3 : 1;
 *     }
 * }
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // Default connection: null (uses default database connection)
 * // Default attempts: 1
 * //
 * // Transaction metadata is automatically added to results:
 * // - For objects: $result->_transaction property
 * // - For arrays: $result['_transaction'] key
 * // - For other types: Wrapped in array with 'data' and '_transaction' keys
 * //
 * // Transaction metadata includes:
 * // - 'used': Always true (indicates transaction was used)
 * // - 'connection': Database connection name ('default' if not specified)
 * // - 'attempts': Number of transaction attempts configured
 * //
 * // Priority order for configuration:
 * // 1. PHP attributes (#[TransactionConnection], #[TransactionAttempts])
 * // 2. Methods (getTransactionConnection(), getTransactionAttempts())
 * // 3. Properties (transactionConnection, transactionAttempts)
 * // 4. Default values
 * //
 * // All database operations within handle() are automatically wrapped.
 * // If any exception is thrown, the transaction is automatically rolled back.
 *
 * @see TransactionDecorator
 * @see TransactionDesignPattern
 * @see TransactionConnection
 * @see TransactionAttempts
 */
trait AsTransaction
{
    //
}
