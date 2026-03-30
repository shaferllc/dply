<?php

namespace App\Actions\Concerns;

use App\Actions\ActionComposition;
use Illuminate\Support\Fluent;

/**
 * Provides object-oriented methods for actions.
 *
 * @example
 * // Action class:
 * class ProcessOrder extends Actions
 * {
 *     use AsObject;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // Usage:
 * ProcessOrder::run($order);
 * ProcessOrder::runIf($shouldProcess, $order);
 * ProcessOrder::runUnless($shouldSkip, $order);
 * ProcessOrder::runWhen(fn() => $condition, $order);
 * ProcessOrder::runOnce($order); // Cached forever
 * ProcessOrder::runOnceWithTtl(3600, $order); // Cached for 1 hour
 * ProcessOrder::runSilently($order); // Returns null on error
 * ProcessOrder::runWith(['timeout' => 30], $order);
 * ProcessOrder::runInBackground($order); // Queued
 * ProcessOrder::runWithRetry(3, 1000, null, $order); // Retry 3 times with 1s delay
 * ProcessOrder::runWhenNotNull($order, $order);
 * ProcessOrder::runWhenNotEmpty($orderData, $order);
 * $action = ProcessOrder::make();
 */
trait AsObject
{
    /**
     * Create a new instance of the action.
     *
     * @return static
     */
    public static function make()
    {
        return app(static::class);
    }

    /**
     * Run the action with the given arguments.
     *
     * @see static::handle()
     */
    public static function run(mixed ...$arguments): mixed
    {
        return static::make()->handle(...$arguments);
    }

    /**
     * Start an action composition chain with this action's result.
     *
     * @param  mixed  ...$arguments  Arguments to pass to this action
     */
    public static function start(mixed ...$arguments): ActionComposition
    {
        $result = static::run(...$arguments);

        return ActionComposition::start($result);
    }

    /**
     * Chain this action to execute after another action.
     *
     * @param  mixed  $previousResult  Result from previous action
     * @param  mixed  ...$additionalArgs  Additional arguments
     * @return mixed Result of this action
     */
    public function chain(mixed $previousResult, ...$additionalArgs): mixed
    {
        return $this->handle($previousResult, ...$additionalArgs);
    }

    /**
     * Run the action if the condition is true.
     */
    public static function runIf(bool $boolean, mixed ...$arguments): mixed
    {
        return $boolean ? static::run(...$arguments) : new Fluent;
    }

    /**
     * Run the action unless the condition is true.
     */
    public static function runUnless(bool $boolean, mixed ...$arguments): mixed
    {
        return static::runIf(! $boolean, ...$arguments);
    }

    /**
     * Run the action when the condition callback returns true.
     *
     * @param  callable(): bool  $condition
     */
    public static function runWhen(callable $condition, mixed ...$arguments): mixed
    {
        return $condition() ? static::run(...$arguments) : new Fluent;
    }

    /**
     * Run the action once, preventing duplicate execution.
     *
     * Uses a cache key based on the action class and arguments to ensure
     * the action only runs once for the same set of arguments.
     * The result is cached forever.
     */
    public static function runOnce(mixed ...$arguments): mixed
    {
        $cacheKey = static::class.':'.md5(serialize($arguments));

        return cache()->rememberForever($cacheKey, function () use ($arguments) {
            return static::run(...$arguments);
        });
    }

    /**
     * Run the action once with a specific TTL.
     *
     * @param  int  $ttl  Time to live in seconds for the cache key
     */
    public static function runOnceWithTtl(int $ttl, mixed ...$arguments): mixed
    {
        $cacheKey = static::class.':'.md5(serialize($arguments));

        return cache()->remember($cacheKey, $ttl, function () use ($arguments) {
            return static::run(...$arguments);
        });
    }

    /**
     * Run the action silently, catching and returning any exceptions.
     *
     * Returns the result on success, or null on failure.
     * Use this when you want to handle errors gracefully.
     */
    public static function runSilently(mixed ...$arguments): mixed
    {
        try {
            return static::run(...$arguments);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Run the action or throw an exception on failure.
     *
     * This is the default behavior, but provides explicit intent
     * when you want to ensure the action succeeds.
     *
     * @throws \Throwable
     */
    public static function runOrFail(mixed ...$arguments): mixed
    {
        return static::run(...$arguments);
    }

    /**
     * Run the action with additional context/options.
     *
     * Useful for passing configuration or context that doesn't
     * fit the main handle() method signature.
     *
     * @param  array<string, mixed>  $context
     */
    public static function runWith(array $context, mixed ...$arguments): mixed
    {
        $instance = static::make();

        foreach ($context as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        return $instance->handle(...$arguments);
    }

    /**
     * Run the action after a delay.
     *
     * @param  int  $seconds  Delay in seconds before execution
     */
    public static function runAfter(int $seconds, mixed ...$arguments): mixed
    {
        if ($seconds <= 0) {
            return static::run(...$arguments);
        }

        sleep($seconds);

        return static::run(...$arguments);
    }

    /**
     * Run the action in the background (queue it).
     *
     * Queues the action execution as a closure job.
     * Returns a Fluent object indicating the action was queued.
     */
    public static function runInBackground(mixed ...$arguments): mixed
    {
        dispatch(function () use ($arguments) {
            return static::run(...$arguments);
        });

        return new Fluent(['queued' => true]);
    }

    /**
     * Run the action with retry logic.
     *
     * @param  int  $times  Maximum number of attempts
     * @param  int  $sleep  Milliseconds to wait between retries
     * @param  callable|null  $when  Callback to determine if exception should be retried
     */
    public static function runWithRetry(int $times = 3, int $sleep = 0, ?callable $when = null, mixed ...$arguments): mixed
    {
        $attempts = 0;

        beginning:
        $attempts++;

        try {
            return static::run(...$arguments);
        } catch (\Throwable $e) {
            if ($attempts >= $times) {
                throw $e;
            }

            if ($when !== null && ! $when($e)) {
                throw $e;
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }

            goto beginning;
        }
    }

    /**
     * Run the action only if the value is not null.
     *
     * Useful for optional chaining patterns.
     */
    public static function runWhenNotNull(mixed $value, mixed ...$arguments): mixed
    {
        return $value !== null ? static::run($value, ...$arguments) : new Fluent;
    }

    /**
     * Run the action only if the value is not empty.
     *
     * Useful for validation before execution.
     */
    public static function runWhenNotEmpty(mixed $value, mixed ...$arguments): mixed
    {
        return ! empty($value) ? static::run($value, ...$arguments) : new Fluent;
    }
}
