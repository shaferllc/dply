<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Processes items in batches to optimize memory and performance.
 *
 * This trait is a marker that enables automatic batch processing via BatchDecorator.
 * When an action uses AsBatch, BatchDesignPattern recognizes it and
 * ActionManager wraps the action with BatchDecorator.
 *
 * How it works:
 * 1. Action uses AsBatch trait (marker)
 * 2. BatchDesignPattern recognizes the trait
 * 3. ActionManager wraps action with BatchDecorator
 * 4. When handle() is called, the decorator:
 *    - Checks if first argument is an array/iterable
 *    - If yes, processes items in batches
 *    - If no, executes normally
 *    - Calls onBatchComplete() after each batch
 *
 * Features:
 * - Automatic batch detection and processing
 * - Configurable batch size
 * - Batch completion callbacks
 * - Memory-efficient processing
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Reduces memory usage for large datasets
 * - Prevents timeouts on large operations
 * - Improves performance with batching
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - Processing large collections
 * - Bulk data imports
 * - Mass updates
 * - File processing
 * - Email sending
 * - Report generation
 * - Data synchronization
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * BatchDecorator, which automatically wraps actions and adds batch processing.
 * This follows the same pattern as AsDebounced, AsLock, AsLogger, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getBatchSize()` method to customize batch size (default: 100)
 * - Set `batchSize` property to customize batch size
 * - Implement `onBatchComplete(array $batch)` for batch completion callbacks
 *
 * @example
 * // ============================================
 * // Example 1: Basic Batch Processing
 * // ============================================
 * class ProcessUsers extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(User $user): void
 *     {
 *         // Process single user
 *         $user->update(['processed_at' => now()]);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 100;
 *     }
 * }
 *
 * // Usage:
 * $users = User::whereNull('processed_at')->get();
 * ProcessUsers::run($users); // Automatically processes in batches of 100
 * @example
 * // ============================================
 * // Example 2: Batch Processing with Additional Arguments
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(User $user, string $message, string $type): void
 *     {
 *         Notification::create([
 *             'user_id' => $user->id,
 *             'message' => $message,
 *             'type' => $type,
 *         ]);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 50;
 *     }
 * }
 *
 * // Usage:
 * $users = User::where('active', true)->get();
 * SendNotification::run($users, 'Welcome!', 'info');
 * // Processes users in batches of 50, passing 'Welcome!' and 'info' to each
 * @example
 * // ============================================
 * // Example 3: Batch Completion Callback
 * // ============================================
 * class ImportProducts extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(array $productData): Product
 *     {
 *         return Product::create($productData);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 200;
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         // Log progress
 *         \Log::info('Processed batch of '.count($batch).' products');
 *
 *         // Update progress indicator
 *         cache()->increment('import.progress', count($batch));
 *     }
 * }
 *
 * // Usage:
 * $products = collect($productDataArray);
 * ImportProducts::run($products);
 * // Processes in batches of 200, logging after each batch
 * @example
 * // ============================================
 * // Example 4: Large File Processing
 * // ============================================
 * class ProcessFiles extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(string $filePath): void
 *     {
 *         // Process single file
 *         $content = file_get_contents($filePath);
 *         // ... process content ...
 *         Storage::put('processed/'.basename($filePath), $processedContent);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 10; // Smaller batches for file I/O
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         // Clear memory after each batch
 *         gc_collect_cycles();
 *     }
 * }
 *
 * // Usage:
 * $files = Storage::files('uploads');
 * ProcessFiles::run($files);
 * // Processes files in batches of 10, clearing memory after each
 * @example
 * // ============================================
 * // Example 5: Database Updates in Batches
 * // ============================================
 * class UpdateUserStatus extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(User $user, string $status): void
 *     {
 *         $user->update(['status' => $status]);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 500;
 *     }
 * }
 *
 * // Usage:
 * $users = User::where('status', 'pending')->get();
 * UpdateUserStatus::run($users, 'active');
 * // Updates users in batches of 500
 * @example
 * // ============================================
 * // Example 6: Email Sending in Batches
 * // ============================================
 * class SendEmails extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(User $user, string $subject, string $body): void
 *     {
 *         Mail::to($user)->send(new GenericMail($subject, $body));
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 25; // Smaller batches to avoid rate limits
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         // Add delay between batches to respect rate limits
 *         sleep(1);
 *     }
 * }
 *
 * // Usage:
 * $users = User::where('subscribed', true)->get();
 * SendEmails::run($users, 'Newsletter', 'Check out our latest updates!');
 * // Sends emails in batches of 25 with 1 second delay between batches
 * @example
 * // ============================================
 * // Example 7: Data Synchronization
 * // ============================================
 * class SyncData extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(array $data): void
 *     {
 *         // Sync single record
 *         ExternalService::sync($data);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 100;
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         // Log synchronization progress
 *         \Log::info('Synced batch of '.count($batch).' records');
 *     }
 * }
 *
 * // Usage:
 * $records = collect($syncData);
 * SyncData::run($records);
 * // Syncs records in batches of 100
 * @example
 * // ============================================
 * // Example 8: Report Generation
 * // ============================================
 * class GenerateReports extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Generate report for single order
 *         $report = ReportGenerator::generate($order);
 *         Storage::put("reports/{$order->id}.pdf", $report);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 20; // Smaller batches for CPU-intensive operations
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         // Update progress
 *         $progress = cache()->increment('reports.progress', count($batch));
 *         \Log::info("Generated {$progress} reports so far");
 *     }
 * }
 *
 * // Usage:
 * $orders = Order::where('status', 'completed')->get();
 * GenerateReports::run($orders);
 * // Generates reports in batches of 20
 * @example
 * // ============================================
 * // Example 9: Image Processing
 * // ============================================
 * class ProcessImages extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(string $imagePath): void
 *     {
 *         // Process single image
 *         $image = Image::make($imagePath);
 *         $image->resize(800, 600)->save("processed/{$imagePath}");
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 15; // Smaller batches for memory-intensive operations
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         // Free memory
 *         gc_collect_cycles();
 *     }
 * }
 *
 * // Usage:
 * $images = Storage::files('uploads/images');
 * ProcessImages::run($images);
 * // Processes images in batches of 15, freeing memory after each
 * @example
 * // ============================================
 * // Example 10: Using Property for Batch Size
 * // ============================================
 * class ProcessItems extends Actions
 * {
 *     use AsBatch;
 *
 *     protected int $batchSize = 250;
 *
 *     public function handle(Item $item): void
 *     {
 *         // Process single item
 *         $item->process();
 *     }
 * }
 *
 * // Usage:
 * $items = Item::all();
 * ProcessItems::run($items);
 * // Uses batchSize property (250) instead of method
 * @example
 * // ============================================
 * // Example 11: Non-Batch Execution (Single Item)
 * // ============================================
 * class ProcessUser extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(User $user): void
 *     {
 *         // Process single user
 *         $user->process();
 *     }
 * }
 *
 * // Usage with single item (not an array):
 * ProcessUser::run($user);
 * // Executes normally, no batching
 *
 * // Usage with array (triggers batching):
 * ProcessUser::run([$user1, $user2, $user3]);
 * // Processes in batches
 * @example
 * // ============================================
 * // Example 12: Complex Batch Processing with Progress
 * // ============================================
 * class ImportData extends Actions
 * {
 *     use AsBatch;
 *
 *     public function handle(array $row): void
 *     {
 *         // Import single row
 *         Import::create($row);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 1000;
 *     }
 *
 *     protected function onBatchComplete(array $batch): void
 *     {
 *         $processed = cache()->increment('import.processed', count($batch));
 *         $total = cache()->get('import.total', 0);
 *
 *         $percentage = $total > 0 ? ($processed / $total) * 100 : 0;
 *
 *         \Log::info("Import progress: {$processed}/{$total} ({$percentage}%)");
 *
 *         // Broadcast progress update
 *         broadcast(new ImportProgressEvent($processed, $total, $percentage));
 *     }
 * }
 *
 * // Usage:
 * cache()->put('import.total', count($data));
 * ImportData::run($data);
 * // Processes in batches of 1000, tracking and broadcasting progress
 */
trait AsBatch
{
    // This is a marker trait - the actual batch processing functionality is handled by BatchDecorator
    // via the BatchDesignPattern and ActionManager
}
