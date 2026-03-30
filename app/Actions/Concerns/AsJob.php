<?php

namespace App\Actions\Concerns;

use App\Actions\ActionManager;
use App\Actions\ActionPendingChain;
use App\Actions\Decorators\JobDecorator;
use App\Actions\Decorators\UniqueJobDecorator;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Fluent;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * Enables actions to be dispatched as queued jobs.
 *
 * This trait provides methods to dispatch actions as Laravel queue jobs.
 * When an action uses AsJob, it can be queued for background processing
 * using JobDecorator or UniqueJobDecorator.
 *
 * How it works:
 * 1. Action uses AsJob trait
 * 2. Call dispatch() methods to queue the action
 * 3. JobDecorator wraps the action and implements ShouldQueue
 * 4. Laravel queue processes the job
 * 5. JobDecorator calls the action's handle() or asJob() method
 *
 * Benefits:
 * - Convert any action into a queued job
 * - Support for unique jobs (prevent duplicates)
 * - Configurable queue, connection, tries, timeout
 * - Job chaining support
 * - Testing helpers (assertPushed, etc.)
 * - Works with Laravel's batch jobs
 * - Automatic serialization of models
 *
 * Note: This trait uses decorators (JobDecorator, UniqueJobDecorator) but
 * requires explicit dispatching. Unlike other concerns (AsLogger, AsLock)
 * that automatically wrap handle() calls, jobs must be explicitly dispatched
 * via dispatch(), dispatchSync(), etc. This is intentional - you don't want
 * every action that uses AsJob to automatically become a job.
 *
 * Configuration:
 * - Set `$jobConnection` property to specify queue connection
 * - Set `$jobQueue` property to specify queue name
 * - Set `$jobTries` property to specify max retry attempts
 * - Set `$jobMaxExceptions` property to specify max exceptions before failing
 * - Set `$jobTimeout` property to specify job timeout in seconds
 * - Set `$jobBackoff` property or implement `getJobBackoff()` for retry delays
 * - Set `$jobRetryUntil` property or implement `getJobRetryUntil()` for retry deadline
 * - Implement `getJobMiddleware()` to add job middleware
 * - Implement `jobFailed(Throwable $e)` to handle job failures
 * - Implement `getJobDisplayName()` to customize job display name
 * - Implement `getJobTags()` to add job tags
 * - Implement `configureJob(JobDecorator $job)` to customize job configuration
 * - For unique jobs: implement `ShouldBeUnique` interface
 * - For unique jobs: set `$jobUniqueFor` or implement `getJobUniqueFor()`
 * - For unique jobs: implement `getJobUniqueId()` for custom unique ID
 * - For unique jobs: implement `getJobUniqueVia()` for custom cache driver
 * - Set `$jobDeleteWhenMissingModels` or implement `getJobDeleteWhenMissingModels()`
 *
 * Dispatch Methods:
 * - `dispatch()` - Queue the job
 * - `dispatchIf($condition)` - Queue only if condition is true
 * - `dispatchUnless($condition)` - Queue only if condition is false
 * - `dispatchSync()` - Execute immediately (synchronously)
 * - `dispatchNow()` - Alias for dispatchSync()
 * - `dispatchAfterResponse()` - Queue after HTTP response is sent
 * - `withChain($chain)` - Chain multiple jobs together
 *
 * Testing:
 * - `assertPushed($times, $callback)` - Assert job was pushed
 * - `assertNotPushed($callback)` - Assert job was not pushed
 * - `assertPushedOn($queue, $times, $callback)` - Assert job was pushed on specific queue
 * - `assertPushedWith($callback, $queue)` - Assert job was pushed with specific parameters
 * - `assertNotPushedWith($callback)` - Assert job was not pushed with specific parameters
 *
 * @property-read string $jobConnection
 * @property-read string $jobQueue
 * @property-read int $jobTries
 * @property-read int $jobMaxExceptions
 * @property-read int $jobTimeout
 *
 * @method void configureJob(JobDecorator|UniqueJobDecorator $job)
 *
 * @property-read int|array $jobBackoff
 *
 * @method int|array getJobBackoff()
 *
 * @property-read \DateTime|int $jobRetryUntil
 *
 * @method \DateTime|int getJobRetryUntil()
 * @method array getJobMiddleware()
 * @method void jobFailed(Throwable $e)
 * @method string getJobDisplayName()
 * @method array getJobTags()
 *
 * @property-read int $jobUniqueFor
 *
 * @method int getJobUniqueFor()
 *
 * @property-read int $jobUniqueId
 *
 * @method int getJobUniqueId()
 * @method int getJobUniqueVia()
 *
 * @property-read bool $jobDeleteWhenMissingModels
 *
 * @method bool getJobDeleteWhenMissingModels()
 *
 * @example
 * // ============================================
 * // Example 1: Basic Job Dispatch
 * // ============================================
 * class SendEmailAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(string $email, string $message): void
 *     {
 *         Mail::to($email)->send(new NotificationMail($message));
 *     }
 * }
 *
 * // Dispatch to queue
 * SendEmailAction::dispatch('user@example.com', 'Hello!');
 *
 * // Execute immediately (synchronously)
 * SendEmailAction::dispatchSync('user@example.com', 'Hello!');
 * @example
 * // ============================================
 * // Example 2: Configure Queue and Connection
 * // ============================================
 * class ProcessOrderAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected string $jobConnection = 'redis';
 *     protected string $jobQueue = 'orders';
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // Job will be queued on 'redis' connection, 'orders' queue
 * ProcessOrderAction::dispatch($order);
 * @example
 * // ============================================
 * // Example 3: Retry Configuration
 * // ============================================
 * class ApiCallAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected int $jobTries = 3;
 *     protected int $jobMaxExceptions = 2;
 *     protected int $jobTimeout = 60;
 *
 *     public function handle(string $url): void
 *     {
 *         // Make API call
 *     }
 * }
 *
 * // Job will retry up to 3 times, timeout after 60 seconds
 * ApiCallAction::dispatch('https://api.example.com/data');
 * @example
 * // ============================================
 * // Example 4: Custom Backoff Strategy
 * // ============================================
 * class RetryableAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected int $jobTries = 5;
 *
 *     protected function getJobBackoff(): array
 *     {
 *         // Exponential backoff: 1s, 2s, 4s, 8s, 16s
 *         return [1, 2, 4, 8, 16];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Retry Until Deadline
 * // ============================================
 * class TimeLimitedAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function getJobRetryUntil(): \DateTime
 *     {
 *         // Stop retrying after 1 hour
 *         return now()->addHour();
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Unique Jobs
 * // ============================================
 * class UpdateCacheAction extends Actions implements ShouldBeUnique
 * {
 *     use AsJob;
 *
 *     protected int $jobUniqueFor = 3600; // Unique for 1 hour
 *
 *     public function handle(string $key): void
 *     {
 *         // Update cache
 *     }
 * }
 *
 * // Only one instance of this job can run at a time
 * // If dispatched again within 1 hour, it will be ignored
 * UpdateCacheAction::dispatch('cache-key');
 * @example
 * // ============================================
 * // Example 7: Custom Unique ID
 * // ============================================
 * class UserUpdateAction extends Actions implements ShouldBeUnique
 * {
 *     use AsJob;
 *
 *     protected function getJobUniqueId(): string
 *     {
 *         // Make unique per user
 *         return 'user-'.$this->userId;
 *     }
 *
 *     public function handle(int $userId): void
 *     {
 *         $this->userId = $userId;
 *         // Update user
 *     }
 * }
 *
 * // Multiple users can be updated simultaneously
 * // But same user won't be updated twice
 * UserUpdateAction::dispatch(1);
 * UserUpdateAction::dispatch(2); // OK - different user
 * UserUpdateAction::dispatch(1); // Ignored - same user already queued
 * @example
 * // ============================================
 * // Example 8: Conditional Dispatch
 * // ============================================
 * class NotificationAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         // Send notification
 *     }
 * }
 *
 * // Only dispatch if user wants notifications
 * NotificationAction::dispatchIf(
 *     $user->wants_notifications,
 *     $user,
 *     'New message!'
 * );
 *
 * // Dispatch unless user is blocked
 * NotificationAction::dispatchUnless(
 *     $user->is_blocked,
 *     $user,
 *     'Welcome!'
 * );
 * @example
 * // ============================================
 * // Example 9: Dispatch After Response
 * // ============================================
 * class LogActivityAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(string $activity): void
 *     {
 *         Activity::log($activity);
 *     }
 * }
 *
 * // In controller:
 * public function store(Request $request)
 * {
 *     // Process request immediately
 *     $result = ProcessRequest::run($request);
 *
 *     // Queue logging to happen after response is sent
 *     LogActivityAction::dispatchAfterResponse('Request processed');
 *
 *     return $result;
 * }
 * @example
 * // ============================================
 * // Example 10: Job Chaining
 * // ============================================
 * class ProcessPaymentAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process payment
 *     }
 * }
 *
 * class SendReceiptAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Send receipt
 *     }
 * }
 *
 * class UpdateInventoryAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Update inventory
 *     }
 * }
 *
 * // Chain jobs: payment -> receipt -> inventory
 * ProcessPaymentAction::withChain([
 *     new SendReceiptAction($order),
 *     new UpdateInventoryAction($order),
 * ])->dispatch($order);
 * @example
 * // ============================================
 * // Example 11: Job Failure Handling
 * // ============================================
 * class CriticalAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected int $jobTries = 3;
 *
 *     protected function jobFailed(Throwable $e): void
 *     {
 *         // Log failure
 *         Log::error('Critical action failed', [
 *             'exception' => $e,
 *             'action' => static::class,
 *         ]);
 *
 *         // Notify administrators
 *         Notification::route('slack', config('logging.slack'))
 *             ->notify(new CriticalActionFailed($e));
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Critical logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Custom Job Display Name
 * // ============================================
 * class GenerateReportAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function getJobDisplayName(): string
 *     {
 *         return "Generate Report: {$this->reportType}";
 *     }
 *
 *     public function handle(string $reportType): void
 *     {
 *         $this->reportType = $reportType;
 *         // Generate report
 *     }
 * }
 *
 * // Job will show as "Generate Report: sales" in Horizon/queue monitor
 * @example
 * // ============================================
 * // Example 13: Job Tags
 * // ============================================
 * class TaggedAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function getJobTags(): array
 *     {
 *         return [
 *             'user:'.$this->userId,
 *             'type:'.$this->type,
 *             'priority:high',
 *         ];
 *     }
 *
 *     public function handle(int $userId, string $type): void
 *     {
 *         $this->userId = $userId;
 *         $this->type = $type;
 *         // Action logic
 *     }
 * }
 *
 * // Tags help filter and monitor jobs in Horizon
 * @example
 * // ============================================
 * // Example 14: Job Middleware
 * // ============================================
 * class RateLimitedAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function getJobMiddleware(): array
 *     {
 *         return [
 *             new \Illuminate\Queue\Middleware\RateLimited('api-calls'),
 *         ];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Configure Job Dynamically
 * // ============================================
 * class ConfigurableAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function configureJob(JobDecorator $job): void
 *     {
 *         // Set queue based on priority
 *         if ($this->priority === 'high') {
 *             $job->onQueue('high-priority');
 *         } else {
 *             $job->onQueue('default');
 *         }
 *
 *         // Set tries based on importance
 *         if ($this->isCritical) {
 *             $job->setTries(5);
 *         }
 *     }
 *
 *     public function handle(string $priority, bool $isCritical): void
 *     {
 *         $this->priority = $priority;
 *         $this->isCritical = $isCritical;
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Using asJob() Method
 * // ============================================
 * class CustomJobAction extends Actions
 * {
 *     use AsJob;
 *
 *     // If asJob() exists, it's called instead of handle()
 *     public function asJob(JobDecorator $job): void
 *     {
 *         // Access job instance for advanced configuration
 *         $job->setTries(10);
 *
 *         // Call handle with job context
 *         $this->handle($job->getParameters());
 *     }
 *
 *     public function handle(array $params): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Batch Jobs
 * // ============================================
 * class BatchableAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function asJob(JobDecorator $job, \Illuminate\Bus\Batch $batch): void
 *     {
 *         // Access batch for progress tracking
 *         $batch->progress();
 *
 *         // Action logic
 *     }
 * }
 *
 * // Dispatch as batch
 * \Illuminate\Support\Facades\Bus::batch([
 *     new BatchableAction(),
 *     new BatchableAction(),
 *     new BatchableAction(),
 * ])->dispatch();
 * @example
 * // ============================================
 * // Example 18: Delete When Missing Models
 * // ============================================
 * class ModelDependentAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected bool $jobDeleteWhenMissingModels = true;
 *
 *     public function handle(Order $order): void
 *     {
 *         // If order is deleted before job runs, job is automatically deleted
 *         $order->process();
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Combining with Other Decorators
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsJob;
 *     use AsLogger;
 *     use AsLock;
 *     use AsLifecycle;
 *
 *     protected string $jobQueue = 'processing';
 *     protected int $jobTries = 3;
 *
 *     public function handle(Data $data): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function beforeHandle(Data $data): void
 *     {
 *         // Lifecycle hook
 *     }
 * }
 *
 * // When dispatched, job runs with:
 * // - Logging (via LoggerDecorator)
 * // - Locking (via LockDecorator)
 * // - Lifecycle hooks (via LifecycleDecorator)
 * ComprehensiveAction::dispatch($data);
 * @example
 * // ============================================
 * // Example 20: Testing Jobs
 * // ============================================
 * class TestableAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(string $message): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * Queue::fake();
 *
 * TestableAction::dispatch('Hello');
 *
 * // Assert job was pushed
 * TestableAction::assertPushed();
 *
 * // Assert job was pushed with specific parameters
 * TestableAction::assertPushedWith(['Hello']);
 *
 * // Assert job was pushed on specific queue
 * TestableAction::assertPushedOn('default', 1);
 *
 * // Assert job was not pushed
 * TestableAction::assertNotPushed();
 * @example
 * // ============================================
 * // Example 21: Dynamic Queue Selection
 * // ============================================
 * class DynamicQueueAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function configureJob(JobDecorator $job): void
 *     {
 *         // Select queue based on user tier
 *         $user = $this->getUser();
 *         $queue = match($user->tier) {
 *             'premium' => 'high-priority',
 *             'standard' => 'default',
 *             'free' => 'low-priority',
 *         };
 *
 *         $job->onQueue($queue);
 *     }
 *
 *     public function handle(User $user): void
 *     {
 *         $this->user = $user;
 *         // Action logic
 *     }
 *
 *     protected function getUser(): User
 *     {
 *         return $this->user;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 22: Environment-Based Configuration
 * // ============================================
 * class EnvironmentAwareAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function configureJob(JobDecorator $job): void
 *     {
 *         // In production, use dedicated queue
 *         if (app()->environment('production')) {
 *             $job->onConnection('redis');
 *             $job->onQueue('production');
 *             $job->setTries(5);
 *         } else {
 *             // In development, execute synchronously
 *             // (or use sync queue)
 *             $job->onConnection('sync');
 *         }
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 23: Priority-Based Retry
 * // ============================================
 * class PriorityRetryAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected function getJobBackoff(): array
 *     {
 *         // High priority: retry quickly
 *         if ($this->priority === 'high') {
 *             return [1, 2, 4]; // 1s, 2s, 4s
 *         }
 *
 *         // Low priority: retry slowly
 *         return [60, 300, 900]; // 1m, 5m, 15m
 *     }
 *
 *     public function handle(string $priority): void
 *     {
 *         $this->priority = $priority;
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 24: Job with Timeout Per Attempt
 * // ============================================
 * class TimeoutAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected int $jobTimeout = 120; // 2 minutes per attempt
 *     protected int $jobTries = 3;
 *
 *     public function handle(): void
 *     {
 *         // Long-running operation
 *         // Each attempt gets 2 minutes
 *     }
 * }
 * @example
 * // ============================================
 * // Example 25: Custom Unique Via Cache
 * // ============================================
 * class CustomCacheAction extends Actions implements ShouldBeUnique
 * {
 *     use AsJob;
 *
 *     protected function getJobUniqueVia()
 *     {
 *         // Use custom cache store for uniqueness
 *         return Cache::store('redis');
 *     }
 *
 *     protected function getJobUniqueId(): string
 *     {
 *         return 'custom-'.$this->id;
 *     }
 *
 *     public function handle(int $id): void
 *     {
 *         $this->id = $id;
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 26: Job with Model Serialization
 * // ============================================
 * class ModelAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function handle(User $user, Post $post): void
 *     {
 *         // Models are automatically serialized/unserialized
 *         // Only model keys are stored in queue
 *         $user->update(['last_activity' => now()]);
 *         $post->increment('views');
 *     }
 * }
 *
 * // Models are serialized efficiently
 * ModelAction::dispatch($user, $post);
 * @example
 * // ============================================
 * // Example 27: Job with Progress Tracking
 * // ============================================
 * class ProgressAction extends Actions
 * {
 *     use AsJob;
 *
 *     public function asJob(JobDecorator $job, \Illuminate\Bus\Batch $batch = null): void
 *     {
 *         $items = $this->getItems();
 *         $total = count($items);
 *
 *         foreach ($items as $index => $item) {
 *             $this->processItem($item);
 *
 *             // Update batch progress if in batch
 *             if ($batch) {
 *                 $batch->progress();
 *             }
 *         }
 *     }
 *
 *     protected function getItems(): array
 *     {
 *         return [];
 *     }
 *
 *     protected function processItem($item): void
 *     {
 *         // Process item
 *     }
 * }
 * @example
 * // ============================================
 * // Example 28: Job with Conditional Retry
 * // ============================================
 * class ConditionalRetryAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected int $jobTries = 5;
 *
 *     protected function jobFailed(Throwable $e): void
 *     {
 *         // Only retry on specific exceptions
 *         if ($e instanceof \Illuminate\Http\Client\RequestException) {
 *             // Network error - might succeed on retry
 *             return;
 *         }
 *
 *         // Other exceptions - don't retry
 *         throw $e;
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 29: Job with Max Exceptions
 * // ============================================
 * class ExceptionLimitedAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected int $jobTries = 10;
 *     protected int $jobMaxExceptions = 3;
 *
 *     // Job will fail after 3 exceptions, even if tries remain
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 30: Complex Job Configuration
 * // ============================================
 * class ComplexAction extends Actions
 * {
 *     use AsJob;
 *
 *     protected string $jobConnection = 'redis';
 *     protected string $jobQueue = 'default';
 *     protected int $jobTries = 5;
 *     protected int $jobMaxExceptions = 2;
 *     protected int $jobTimeout = 300;
 *     protected bool $jobDeleteWhenMissingModels = true;
 *
 *     protected function getJobBackoff(): array
 *     {
 *         return [10, 30, 60, 120, 300];
 *     }
 *
 *     protected function getJobRetryUntil(): \DateTime
 *     {
 *         return now()->addHours(2);
 *     }
 *
 *     protected function getJobMiddleware(): array
 *     {
 *         return [
 *             new \Illuminate\Queue\Middleware\RateLimited('api'),
 *         ];
 *     }
 *
 *     protected function getJobDisplayName(): string
 *     {
 *         return "Complex Action: {$this->id}";
 *     }
 *
 *     protected function getJobTags(): array
 *     {
 *         return ['complex', 'important', "id:{$this->id}"];
 *     }
 *
 *     protected function configureJob(JobDecorator $job): void
 *     {
 *         // Final configuration adjustments
 *     }
 *
 *     protected function jobFailed(Throwable $e): void
 *     {
 *         Log::error('Complex action failed', ['exception' => $e]);
 *     }
 *
 *     public function handle(int $id): void
 *     {
 *         $this->id = $id;
 *         // Complex action logic
 *     }
 * }
 */
trait AsJob
{
    public static function makeJob(mixed ...$arguments): JobDecorator
    {
        if (static::jobShouldBeUnique()) {
            return static::makeUniqueJob(...$arguments);
        }

        return new ActionManager::$jobDecorator(static::class, ...$arguments);
    }

    public static function makeUniqueJob(mixed ...$arguments): UniqueJobDecorator
    {
        return new ActionManager::$uniqueJobDecorator(static::class, ...$arguments);
    }

    protected static function jobShouldBeUnique(): bool
    {
        return is_subclass_of(static::class, ShouldBeUnique::class);
    }

    public static function dispatch(mixed ...$arguments): PendingDispatch
    {
        return new PendingDispatch(static::makeJob(...$arguments));
    }

    public static function dispatchIf(bool $boolean, mixed ...$arguments): PendingDispatch|Fluent
    {
        return $boolean ? static::dispatch(...$arguments) : new Fluent;
    }

    public static function dispatchUnless(bool $boolean, mixed ...$arguments): PendingDispatch|Fluent
    {
        return static::dispatchIf(! $boolean, ...$arguments);
    }

    public static function dispatchSync(mixed ...$arguments): mixed
    {
        return app(Dispatcher::class)->dispatchSync(static::makeJob(...$arguments));
    }

    public static function dispatchNow(mixed ...$arguments): mixed
    {
        return static::dispatchSync(...$arguments);
    }

    public static function dispatchAfterResponse(mixed ...$arguments): void
    {
        static::dispatch(...$arguments)->afterResponse();
    }

    public static function withChain(array $chain): ActionPendingChain
    {
        return new ActionPendingChain(static::class, $chain);
    }

    public static function assertPushed(Closure|int|null $times = null, ?Closure $callback = null): void
    {
        if ($times instanceof Closure) {
            $callback = $times;
            $times = null;
        }

        $decoratorClass = static::jobShouldBeUnique()
            ? ActionManager::$uniqueJobDecorator
            : ActionManager::$jobDecorator;

        $count = Queue::pushed($decoratorClass, function (JobDecorator $job, $queue) use ($callback) {
            if (! $job->decorates(static::class)) {
                return false;
            }

            if (! $callback) {
                return true;
            }

            return $callback($job->getAction(), $job->getParameters(), $job, $queue);
        })->count();

        $job = static::class;

        if (is_null($times)) {
            PHPUnit::assertTrue(
                $count > 0,
                "The expected [{$job}] job was not pushed."
            );
        } elseif ($times === 0) {
            PHPUnit::assertTrue(
                $count === 0,
                "The unexpected [{$job}] job was pushed."
            );
        } else {
            PHPUnit::assertSame(
                $times,
                $count,
                "The expected [{$job}] job was pushed {$count} times instead of {$times} times."
            );
        }
    }

    public static function assertNotPushed(?Closure $callback = null): void
    {
        static::assertPushed(0, $callback);
    }

    public static function assertPushedOn(string $queue, Closure|int|null $times = null, ?Closure $callback = null): void
    {
        if ($times instanceof Closure) {
            $callback = $times;
            $times = null;
        }

        static::assertPushed($times, function ($action, $parameters, $job, $pushedQueue) use ($callback, $queue) {
            if ($pushedQueue !== $queue) {
                return false;
            }

            return $callback ? $callback(...func_get_args()) : true;
        });
    }

    public static function assertPushedWith(Closure|array $callback, ?string $queue = null): void
    {
        if (is_array($callback)) {
            $callback = fn (...$params) => $params === $callback;
        }

        static::assertPushed(
            fn ($action, $params, JobDecorator $job, $q) => $callback(...$params) && (is_null($queue) || $q === $queue)
        );
    }

    public static function assertNotPushedWith(Closure|array $callback): void
    {
        if (is_array($callback)) {
            $callback = fn (...$params) => $params === $callback;
        }

        static::assertNotPushed(fn ($action, $params, JobDecorator $job) => $callback(...$job->getParameters()));
    }
}
