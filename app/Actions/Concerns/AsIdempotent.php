<?php

namespace App\Actions\Concerns;

/**
 * Ensures actions are idempotent - safe to retry without side effects.
 *
 * This trait is a marker that enables automatic idempotency protection via IdempotentDecorator.
 * When an action uses AsIdempotent, IdempotentDesignPattern recognizes it and
 * ActionManager wraps the action with IdempotentDecorator.
 *
 * How it works:
 * 1. Action uses AsIdempotent trait (marker)
 * 2. IdempotentDesignPattern recognizes the trait
 * 3. ActionManager wraps action with IdempotentDecorator
 * 4. When handle() is called, the decorator:
 *    - Generates an idempotency key from arguments
 *    - Checks if result is already cached
 *    - Returns cached result if found (idempotent)
 *    - Executes action if not cached
 *    - Caches the result for future calls
 *    - Returns the result
 *
 * Benefits:
 * - Prevents duplicate execution
 * - Safe to retry operations
 * - Returns cached results instantly
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - Customizable key generation
 * - Configurable TTL
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * IdempotentDecorator, which automatically wraps actions and adds idempotency protection.
 * This follows the same pattern as AsLogger, AsLock, AsJWT, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getIdempotencyKey(...$arguments)` to customize key generation
 * - Set `$idempotencyTtl` property or implement `getIdempotencyTtl()` to customize TTL
 *
 * Idempotency Key:
 * - Default: SHA256 hash of serialized arguments
 * - Custom: Implement `getIdempotencyKey()` method
 * - Should uniquely identify the operation
 * - Should be deterministic (same arguments = same key)
 *
 * Cache TTL:
 * - Default: 3600 seconds (1 hour)
 * - Custom: Set `$idempotencyTtl` property or implement `getIdempotencyTtl()`
 * - Determines how long results are cached
 * - Should match the expected retry window
 *
 * @example
 * // ============================================
 * // Example 1: Basic Idempotency
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(Order $order, float $amount): void
 *     {
 *         // Payment processing - safe to retry
 *         Payment::create([
 *             'order_id' => $order->id,
 *             'amount' => $amount,
 *         ]);
 *     }
 * }
 *
 * // First call executes
 * ProcessPayment::run($order, 100.00);
 *
 * // Second call with same arguments returns cached result (no duplicate payment)
 * ProcessPayment::run($order, 100.00);
 * @example
 * // ============================================
 * // Example 2: Custom Idempotency Key
 * // ============================================
 * class CreateInvoice extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(Order $order): Invoice
 *     {
 *         return Invoice::create(['order_id' => $order->id]);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use order ID as key (same order = same invoice)
 *         return 'invoice:'.$arguments[0]->id;
 *     }
 * }
 *
 * // Same order ID always returns same invoice
 * $invoice1 = CreateInvoice::run($order);
 * $invoice2 = CreateInvoice::run($order); // Returns $invoice1
 * @example
 * // ============================================
 * // Example 3: Custom TTL
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsIdempotent;
 *
 *     protected int $idempotencyTtl = 7200; // 2 hours
 *
 *     public function handle(string $reportType, \DateTime $date): Report
 *     {
 *         return Report::generate($reportType, $date);
 *     }
 * }
 *
 * // Results cached for 2 hours
 * @example
 * // ============================================
 * // Example 4: API Request Idempotency
 * // ============================================
 * class SendApiRequest extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $endpoint, array $data): array
 *     {
 *         return Http::post($endpoint, $data)->json();
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use endpoint + data hash as key
 *         return $arguments[0].':'.hash('sha256', json_encode($arguments[1]));
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 300; // 5 minutes for API requests
 *     }
 * }
 *
 * // Prevents duplicate API calls within 5 minutes
 * @example
 * // ============================================
 * // Example 5: User-Specific Idempotency
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         Notification::send($user, $message);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Unique per user + message
 *         return 'notification:'.$arguments[0]->id.':'.md5($arguments[1]);
 *     }
 * }
 *
 * // Same user + same message = only one notification
 * @example
 * // ============================================
 * // Example 6: Transaction Idempotency
 * // ============================================
 * class ProcessTransaction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $transactionId, float $amount): Transaction
 *     {
 *         return Transaction::create([
 *             'transaction_id' => $transactionId,
 *             'amount' => $amount,
 *         ]);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use transaction ID as key
 *         return 'transaction:'.$arguments[0];
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 86400; // 24 hours for transactions
 *     }
 * }
 *
 * // Prevents duplicate transactions with same ID
 * @example
 * // ============================================
 * // Example 7: File Processing Idempotency
 * // ============================================
 * class ProcessFile extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $filePath): array
 *     {
 *         return FileProcessor::process($filePath);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use file path + modification time as key
 *         $path = $arguments[0];
 *         $mtime = filemtime($path);
 *         return 'file:'.md5($path).':'.$mtime;
 *     }
 * }
 *
 * // Same file (same mtime) = cached result
 * // Different file or updated file = new processing
 * @example
 * // ============================================
 * // Example 8: Combining with Other Decorators
 * // ============================================
 * class SecureIdempotentAction extends Actions
 * {
 *     use AsIdempotent;
 *     use AsLock;
 *     use AsLogger;
 *
 *     public function handle(Data $data): Result
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // All decorators work together:
 * // - IdempotentDecorator prevents duplicates
 * // - LockDecorator prevents concurrent execution
 * // - LoggerDecorator tracks execution
 * @example
 * // ============================================
 * // Example 9: Idempotent Job
 * // ============================================
 * class IdempotentJob extends Actions
 * {
 *     use AsIdempotent;
 *     use AsJob;
 *
 *     public function handle(string $task): void
 *     {
 *         // Task processing
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         return 'job:'.$arguments[0];
 *     }
 * }
 *
 * // Job can be retried safely - won't duplicate work
 * IdempotentJob::dispatch('task-1');
 * IdempotentJob::dispatch('task-1'); // Ignored if already processing
 * @example
 * // ============================================
 * // Example 10: Short TTL for Frequent Operations
 * // ============================================
 * class CacheWarmup extends Actions
 * {
 *     use AsIdempotent;
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 60; // 1 minute - cache warmup happens frequently
 *     }
 *
 *     public function handle(): void
 *     {
 *         Cache::put('warm', true, 3600);
 *     }
 * }
 *
 * // Prevents duplicate warmup within 1 minute
 * @example
 * // ============================================
 * // Example 11: Long TTL for Expensive Operations
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsIdempotent;
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 86400; // 24 hours - reports are expensive
 *     }
 *
 *     public function handle(string $reportType): Report
 *     {
 *         // Expensive report generation
 *         return Report::generate($reportType);
 *     }
 * }
 *
 * // Same report type returns cached result for 24 hours
 * @example
 * // ============================================
 * // Example 12: Request-Based Idempotency
 * // ============================================
 * class ProcessRequest extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(Request $request): Response
 *     {
 *         return Response::process($request);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         $request = $arguments[0];
 *         // Use request ID header if available
 *         $idempotencyKey = $request->header('Idempotency-Key');
 *
 *         if ($idempotencyKey) {
 *             return 'request:'.$idempotencyKey;
 *         }
 *
 *         // Fallback to request hash
 *         return 'request:'.hash('sha256', $request->getContent());
 *     }
 * }
 *
 * // Client can send Idempotency-Key header for control
 * @example
 * // ============================================
 * // Example 13: Multi-Argument Key Generation
 * // ============================================
 * class ComplexAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(User $user, string $action, array $params): Result
 *     {
 *         // Complex logic
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         $user = $arguments[0];
 *         $action = $arguments[1];
 *         $params = $arguments[2];
 *
 *         // Create deterministic key from all arguments
 *         return sprintf(
 *             'complex:%s:%s:%s',
 *             $user->id,
 *             $action,
 *             hash('sha256', json_encode($params))
 *         );
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Environment-Based TTL
 * // ============================================
 * class EnvironmentAwareAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         // Shorter TTL in development
 *         if (app()->environment('local')) {
 *             return 60; // 1 minute
 *         }
 *
 *         return 3600; // 1 hour in production
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Idempotent with Lifecycle Hooks
 * // ============================================
 * class LifecycleIdempotentAction extends Actions
 * {
 *     use AsIdempotent;
 *     use AsLifecycle;
 *
 *     public function handle(Data $data): Result
 *     {
 *         // Action logic
 *     }
 *
 *     protected function beforeHandle(Data $data): void
 *     {
 *         // Called even on idempotent hits
 *     }
 *
 *     protected function onSuccess(Data $data, Result $result): void
 *     {
 *         // Only called on actual execution (not cached)
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Database Operation Idempotency
 * // ============================================
 * class CreateRecord extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $externalId, array $data): Model
 *     {
 *         // Create or update record
 *         return Model::updateOrCreate(
 *             ['external_id' => $externalId],
 *             $data
 *         );
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use external ID as key
 *         return 'record:'.$arguments[0];
 *     }
 * }
 *
 * // Prevents duplicate record creation
 * @example
 * // ============================================
 * // Example 17: Webhook Processing Idempotency
 * // ============================================
 * class ProcessWebhook extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $webhookId, array $payload): void
 *     {
 *         // Process webhook
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use webhook ID as key
 *         return 'webhook:'.$arguments[0];
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 604800; // 7 days - webhooks should be idempotent longer
 *     }
 * }
 *
 * // Prevents duplicate webhook processing
 * @example
 * // ============================================
 * // Example 18: Batch Processing Idempotency
 * // ============================================
 * class ProcessBatch extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(array $items): array
 *     {
 *         return array_map(fn($item) => $this->processItem($item), $items);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use sorted item IDs as key
 *         $itemIds = array_map(fn($item) => $item->id, $arguments[0]);
 *         sort($itemIds);
 *         return 'batch:'.hash('sha256', json_encode($itemIds));
 *     }
 *
 *     protected function processItem($item)
 *     {
 *         // Process individual item
 *     }
 * }
 *
 * // Same items (in any order) = cached result
 * @example
 * // ============================================
 * // Example 19: Time-Windowed Idempotency
 * // ============================================
 * class TimeWindowedAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $operation): void
 *     {
 *         // Operation logic
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Include time window (e.g., hour) in key
 *         $window = now()->format('Y-m-d-H');
 *         return 'windowed:'.$arguments[0].':'.$window;
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 3600; // 1 hour window
 *     }
 * }
 *
 * // Same operation in same hour = cached
 * // Same operation in different hour = new execution
 * @example
 * // ============================================
 * // Example 20: User Action Idempotency
 * // ============================================
 * class UserAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(User $user, string $action): void
 *     {
 *         // User action logic
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Include user ID + action + timestamp (rounded to minute)
 *         $user = $arguments[0];
 *         $action = $arguments[1];
 *         $minute = now()->format('Y-m-d H:i');
 *         return "user:{$user->id}:{$action}:{$minute}";
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 120; // 2 minutes
 *     }
 * }
 *
 * // Prevents duplicate user actions within same minute
 * @example
 * // ============================================
 * // Example 21: API Rate Limit Idempotency
 * // ============================================
 * class ApiCall extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $endpoint, array $params): array
 *     {
 *         return Http::get($endpoint, $params)->json();
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use endpoint + params hash
 *         return $arguments[0].':'.hash('sha256', json_encode($arguments[1]));
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 300; // 5 minutes - prevents duplicate API calls
 *     }
 * }
 *
 * // Prevents duplicate API calls within 5 minutes
 * @example
 * // ============================================
 * // Example 22: Email Sending Idempotency
 * // ============================================
 * class SendEmail extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $to, string $subject, string $body): void
 *     {
 *         Mail::raw($body, fn($m) => $m->to($to)->subject($subject));
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use recipient + subject hash
 *         return 'email:'.$arguments[0].':'.md5($arguments[1]);
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 3600; // 1 hour - prevent duplicate emails
 *     }
 * }
 *
 * // Prevents duplicate emails to same recipient with same subject
 * @example
 * // ============================================
 * // Example 23: File Upload Idempotency
 * // ============================================
 * class ProcessUpload extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(UploadedFile $file): string
 *     {
 *         return $file->store('uploads');
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use file hash as key
 *         return 'upload:'.hash_file('sha256', $arguments[0]->path());
 *     }
 * }
 *
 * // Same file (same hash) = cached result
 * @example
 * // ============================================
 * // Example 24: Scheduled Task Idempotency
 * // ============================================
 * class ScheduledTask extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(): void
 *     {
 *         // Scheduled task logic
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use date as key (one execution per day)
 *         return 'scheduled:'.now()->format('Y-m-d');
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         return 86400; // 24 hours
 *     }
 * }
 *
 * // Prevents duplicate execution on same day
 * @example
 * // ============================================
 * // Example 25: Idempotent with Custom Cache Store
 * // ============================================
 * class CustomCacheAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     // Note: IdempotentDecorator uses Cache facade
 *     // To use custom cache store, configure cache in config/cache.php
 *     // or use Cache::store('custom') in the decorator
 * }
 * @example
 * // ============================================
 * // Example 26: Testing Idempotent Actions
 * // ============================================
 * class TestableAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $input): string
 *     {
 *         return strtoupper($input);
 *     }
 * }
 *
 * // In tests:
 * Cache::flush(); // Clear cache
 *
 * $result1 = TestableAction::run('hello');
 * $result2 = TestableAction::run('hello'); // Returns cached $result1
 *
 * expect($result1)->toBe($result2);
 * @example
 * // ============================================
 * // Example 27: Idempotent with Result Transformation
 * // ============================================
 * class TransformAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive transformation
 *         return array_map(fn($item) => $this->transform($item), $data);
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         // Use data hash as key
 *         return 'transform:'.hash('sha256', json_encode($arguments[0]));
 *     }
 *
 *     protected function transform($item)
 *     {
 *         // Transform logic
 *     }
 * }
 *
 * // Same data = cached transformation result
 * @example
 * // ============================================
 * // Example 28: Idempotent with Expiration Check
 * // ============================================
 * class ExpiringAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(): Result
 *     {
 *         return Result::generate();
 *     }
 *
 *     protected function getIdempotencyTtl(): int
 *     {
 *         // Shorter TTL for time-sensitive operations
 *         return 300; // 5 minutes
 *     }
 * }
 *
 * // Results expire after 5 minutes, allowing fresh execution
 * @example
 * // ============================================
 * // Example 29: Idempotent with Metadata
 * // ============================================
 * class MetadataAction extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(string $key): array
 *     {
 *         return ['data' => 'value', 'key' => $key];
 *     }
 *
 *     // The decorator stores 'executed_at' in cache
 *     // You can access it via Cache::get($cacheKey)['executed_at']
 * }
 * @example
 * // ============================================
 * // Example 30: Complex Idempotent Workflow
 * // ============================================
 * class ComplexWorkflow extends Actions
 * {
 *     use AsIdempotent;
 *     use AsLock;
 *     use AsLogger;
 *     use AsLifecycle;
 *
 *     protected int $idempotencyTtl = 7200; // 2 hours
 *
 *     public function handle(WorkflowData $data): WorkflowResult
 *     {
 *         // Complex workflow logic
 *     }
 *
 *     protected function getIdempotencyKey(...$arguments): string
 *     {
 *         $data = $arguments[0];
 *         return 'workflow:'.$data->workflowId.':'.$data->version;
 *     }
 *
 *     protected function beforeHandle(WorkflowData $data): void
 *     {
 *         // Lifecycle hook
 *     }
 * }
 *
 * // All decorators work together:
 * // - IdempotentDecorator prevents duplicates
 * // - LockDecorator prevents concurrent execution
 * // - LoggerDecorator tracks execution
 * // - LifecycleDecorator provides hooks
 */
trait AsIdempotent
{
    // This is a marker trait - the actual idempotency is handled by IdempotentDecorator
    // via the IdempotentDesignPattern and ActionManager
}
