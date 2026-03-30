<?php

namespace App\Actions\Concerns;

/**
 * Defers action execution until result is actually needed (lazy evaluation).
 *
 * This trait is a marker that enables automatic lazy evaluation via LazyDecorator.
 * When an action uses AsLazy, LazyDesignPattern recognizes it and
 * ActionManager wraps the action with LazyDecorator.
 *
 * How it works:
 * 1. Action uses AsLazy trait (marker)
 * 2. LazyDesignPattern recognizes the trait
 * 3. ActionManager wraps action with LazyDecorator
 * 4. When handle() is called, arguments are stored but execution is deferred
 * 5. Execution happens when:
 *    - get() is called explicitly
 *    - Result is accessed via magic methods
 *    - Result is used in operations
 *
 * Benefits:
 * - Deferred execution - only runs when needed
 * - Result caching - executes only once
 * - Memory efficient - doesn't hold results until accessed
 * - Performance optimization - skip expensive operations if not needed
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * LazyDecorator, which automatically wraps actions and adds lazy evaluation.
 * This follows the same pattern as AsLogger, AsMetrics, AsLock, and other
 * decorator-based concerns.
 *
 * Usage Pattern:
 * - Create action instance: $lazy = Action::make($args)
 * - Access result: $result = $lazy->get()
 * - Check if executed: $lazy->isExecuted()
 * - Reset for re-execution: $lazy->reset()
 *
 * @example
 * // ============================================
 * // Example 1: Basic Lazy Evaluation
 * // ============================================
 * class ExpensiveCalculation extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive operation
 *         return performExpensiveCalculation($data);
 *     }
 * }
 *
 * // Usage:
 * $lazy = ExpensiveCalculation::make($data);
 * // Calculation hasn't run yet
 *
 * $result = $lazy->get(); // Now it executes
 * // Result is cached - subsequent get() calls return cached result
 * @example
 * // ============================================
 * // Example 2: Conditional Execution
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(User $user): array
 *     {
 *         // Expensive report generation
 *         return generateReport($user);
 *     }
 * }
 *
 * // Usage:
 * $report = GenerateReport::make($user);
 *
 * // Only generate if user actually views the report
 * if ($user->wantsReport()) {
 *     $data = $report->get(); // Executes only if needed
 * }
 * @example
 * // ============================================
 * // Example 3: Lazy Database Queries
 * // ============================================
 * class GetUserPosts extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(User $user): \Illuminate\Database\Eloquent\Collection
 *     {
 *         // Expensive query
 *         return $user->posts()->with('comments', 'likes')->get();
 *     }
 * }
 *
 * // Usage:
 * $posts = GetUserPosts::make($user);
 * // Query hasn't run yet
 *
 * if ($showPosts) {
 *     $postsList = $posts->get(); // Query executes now
 * }
 * @example
 * // ============================================
 * // Example 4: Lazy API Calls
 * // ============================================
 * class FetchExternalData extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         // Expensive API call
 *         return Http::get($endpoint)->json();
 *     }
 * }
 *
 * // Usage:
 * $data = FetchExternalData::make($endpoint);
 * // API call hasn't been made yet
 *
 * if ($needsData) {
 *     $result = $data->get(); // API call happens now
 * }
 * @example
 * // ============================================
 * // Example 5: Checking Execution Status
 * // ============================================
 * class ProcessData extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $data): array
 *     {
 *         return processData($data);
 *     }
 * }
 *
 * // Usage:
 * $processor = ProcessData::make($data);
 *
 * if (! $processor->isExecuted()) {
 *     logger()->info('Data processing deferred');
 * }
 *
 * $result = $processor->get();
 *
 * if ($processor->isExecuted()) {
 *     logger()->info('Data processing completed');
 * }
 * @example
 * // ============================================
 * // Example 6: Resetting Lazy State
 * // ============================================
 * class CalculateTotal extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $items): float
 *     {
 *         return array_sum(array_column($items, 'price'));
 *     }
 * }
 *
 * // Usage:
 * $calculator = CalculateTotal::make($items);
 * $total1 = $calculator->get(); // Executes
 *
 * // Update items
 * $items[] = ['price' => 10];
 *
 * // Reset to recalculate with new data
 * $calculator->reset();
 * $calculator->handle($items);
 * $total2 = $calculator->get(); // Executes again with new data
 * @example
 * // ============================================
 * // Example 7: Combining with Other Decorators
 * // ============================================
 * class ExpensiveOperation extends Actions
 * {
 *     use AsLazy;
 *     use AsCachedResult;
 *     use AsLogger;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive operation
 *         return processData($data);
 *     }
 * }
 *
 * // All decorators work together:
 * // - LazyDecorator defers execution
 * // - CachedResultDecorator caches results
 * // - LoggerDecorator tracks execution
 * @example
 * // ============================================
 * // Example 8: Lazy Collection Processing
 * // ============================================
 * class ProcessCollection extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(\Illuminate\Support\Collection $items): \Illuminate\Support\Collection
 *     {
 *         // Expensive collection processing
 *         return $items->map(fn($item) => expensiveOperation($item));
 *     }
 * }
 *
 * // Usage:
 * $processor = ProcessCollection::make($largeCollection);
 * // Processing hasn't started
 *
 * // Only process if collection is actually used
 * if ($needsProcessed) {
 *     $processed = $processor->get();
 * }
 * @example
 * // ============================================
 * // Example 9: Lazy File Processing
 * // ============================================
 * class ProcessLargeFile extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Expensive file processing
 *         return processFile($filePath);
 *     }
 * }
 *
 * // Usage:
 * $processor = ProcessLargeFile::make($filePath);
 * // File hasn't been read yet
 *
 * if ($shouldProcess) {
 *     $data = $processor->get(); // File processing happens now
 * }
 * @example
 * // ============================================
 * // Example 10: Lazy Image Processing
 * // ============================================
 * class GenerateThumbnail extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(string $imagePath): string
 *     {
 *         // Expensive image processing
 *         return generateThumbnail($imagePath);
 *     }
 * }
 *
 * // Usage:
 * $thumbnail = GenerateThumbnail::make($imagePath);
 * // Image processing hasn't started
 *
 * // Only generate if thumbnail is actually needed
 * if ($showThumbnail) {
 *     $thumbnailPath = $thumbnail->get();
 * }
 * @example
 * // ============================================
 * // Example 11: Lazy Pagination
 * // ============================================
 * class GetPaginatedResults extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(int $page = 1): \Illuminate\Contracts\Pagination\LengthAwarePaginator
 *     {
 *         // Expensive query with pagination
 *         return Model::paginate(20, ['*'], 'page', $page);
 *     }
 * }
 *
 * // Usage:
 * $results = GetPaginatedResults::make(1);
 * // Query hasn't run yet
 *
 * // Only fetch if page is actually viewed
 * if ($user->isOnPage(1)) {
 *     $page = $results->get();
 * }
 * @example
 * // ============================================
 * // Example 12: Lazy Aggregation
 * // ============================================
 * class CalculateStatistics extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(\Illuminate\Support\Collection $data): array
 *     {
 *         // Expensive statistical calculations
 *         return [
 *             'mean' => $data->avg('value'),
 *             'median' => $data->median('value'),
 *             'mode' => $data->mode('value'),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $stats = CalculateStatistics::make($largeDataset);
 * // Calculations haven't run yet
 *
 * // Only calculate if statistics are actually displayed
 * if ($showStats) {
 *     $statistics = $stats->get();
 * }
 * @example
 * // ============================================
 * // Example 13: Lazy Search Results
 * // ============================================
 * class SearchDatabase extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(string $query): \Illuminate\Support\Collection
 *     {
 *         // Expensive search query
 *         return Model::where('name', 'like', "%{$query}%")->get();
 *     }
 * }
 *
 * // Usage:
 * $search = SearchDatabase::make($query);
 * // Search hasn't run yet
 *
 * // Only search if user actually submits
 * if ($userSubmitted) {
 *     $results = $search->get();
 * }
 * @example
 * // ============================================
 * // Example 14: Lazy Template Rendering
 * // ============================================
 * class RenderTemplate extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(string $template, array $data): string
 *     {
 *         // Expensive template rendering
 *         return view($template, $data)->render();
 *     }
 * }
 *
 * // Usage:
 * $renderer = RenderTemplate::make('complex-template', $data);
 * // Rendering hasn't started
 *
 * // Only render if template is actually needed
 * if ($shouldRender) {
 *     $html = $renderer->get();
 * }
 * @example
 * // ============================================
 * // Example 15: Lazy Cache Warming
 * // ============================================
 * class WarmCache extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(string $key): mixed
 *     {
 *         // Expensive cache warming operation
 *         $data = fetchExpensiveData();
 *         Cache::put($key, $data, 3600);
 *         return $data;
 *     }
 * }
 *
 * // Usage:
 * $warmer = WarmCache::make('expensive-data');
 * // Cache warming hasn't started
 *
 * // Only warm cache if it's actually needed
 * if (Cache::missing('expensive-data')) {
 *     $data = $warmer->get();
 * }
 * @example
 * // ============================================
 * // Example 16: Lazy Email Generation
 * // ============================================
 * class GenerateEmailContent extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(Email $email): string
 *     {
 *         // Expensive email content generation
 *         return renderEmailTemplate($email);
 *     }
 * }
 *
 * // Usage:
 * $generator = GenerateEmailContent::make($email);
 * // Content generation hasn't started
 *
 * // Only generate if email is actually being sent
 * if ($shouldSend) {
 *     $content = $generator->get();
 *     Mail::raw($content, fn($m) => $m->to($email->to));
 * }
 * @example
 * // ============================================
 * // Example 17: Lazy Export Generation
 * // ============================================
 * class GenerateExport extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $filters): string
 *     {
 *         // Expensive export generation
 *         return generateCsvExport($filters);
 *     }
 * }
 *
 * // Usage:
 * $exporter = GenerateExport::make($filters);
 * // Export hasn't been generated
 *
 * // Only generate if user actually downloads
 * if ($user->requestedDownload()) {
 *     $filePath = $exporter->get();
 *     return response()->download($filePath);
 * }
 * @example
 * // ============================================
 * // Example 18: Lazy Validation
 * // ============================================
 * class ValidateComplexData extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $data): \Illuminate\Contracts\Validation\Validator
 *     {
 *         // Expensive validation with external API calls
 *         return Validator::make($data, $this->getRules());
 *     }
 * }
 *
 * // Usage:
 * $validator = ValidateComplexData::make($data);
 * // Validation hasn't run yet
 *
 * // Only validate if form is actually submitted
 * if ($request->isMethod('post')) {
 *     $validator = $validator->get();
 *     if ($validator->fails()) {
 *         return back()->withErrors($validator);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Lazy Relationship Loading
 * // ============================================
 * class LoadUserRelationships extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(User $user): User
 *     {
 *         // Expensive relationship loading
 *         return $user->load(['posts', 'comments', 'likes', 'followers']);
 *     }
 * }
 *
 * // Usage:
 * $loader = LoadUserRelationships::make($user);
 * // Relationships haven't been loaded
 *
 * // Only load if relationships are actually needed
 * if ($showRelationships) {
 *     $user = $loader->get();
 * }
 * @example
 * // ============================================
 * // Example 20: Lazy Report Generation
 * // ============================================
 * class GenerateAnalyticsReport extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): array
 *     {
 *         // Expensive report generation
 *         return generateAnalytics($startDate, $endDate);
 *     }
 * }
 *
 * // Usage:
 * $report = GenerateAnalyticsReport::make($startDate, $endDate);
 * // Report hasn't been generated
 *
 * // Only generate if user actually views the report
 * if ($user->wantsReport()) {
 *     $analytics = $report->get();
 *     return view('reports.analytics', ['data' => $analytics]);
 * }
 * @example
 * // ============================================
 * // Example 21: Lazy with Magic Methods
 * // ============================================
 * class GetUserProfile extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(User $user): User
 *     {
 *         // Load profile data
 *         return $user->load('profile');
 *     }
 * }
 *
 * // Usage:
 * $profile = GetUserProfile::make($user);
 *
 * // Access properties directly (triggers execution)
 * $name = $profile->name; // Executes and returns user->name
 * $email = $profile->email; // Returns cached result
 *
 * // Call methods directly (triggers execution)
 * $fullName = $profile->getFullName(); // Executes and calls method
 * @example
 * // ============================================
 * // Example 22: Lazy in Blade Templates
 * // ============================================
 * class GetRecentPosts extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(User $user): \Illuminate\Support\Collection
 *     {
 *         return $user->posts()->latest()->limit(10)->get();
 *     }
 * }
 *
 * // In Blade template:
 *
 * @php
 *     $posts = \App\Actions\GetRecentPosts::make($user);
 *
 * @endphp
 *
 * @if($posts->isExecuted())
 *     <p>Posts loaded</p>
 *
 * @endif
 *
 * @foreach($posts->get() as $post)
 *     {{ $post->title }}
 *
 * @endforeach
 *
 * @example
 * // ============================================
 * // Example 23: Lazy API Response Building
 * // ============================================
 * class BuildApiResponse extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive response transformation
 *         return transformApiResponse($data);
 *     }
 * }
 *
 * // Usage in controller:
 * $builder = BuildApiResponse::make($data);
 * // Transformation hasn't started
 *
 * // Only transform if API is actually called
 * if ($request->wantsJson()) {
 *     return response()->json($builder->get());
 * }
 * @example
 * // ============================================
 * // Example 24: Lazy with Conditional Logic
 * // ============================================
 * class ProcessConditionalData extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive processing
 *         return processData($data);
 *     }
 * }
 *
 * // Usage:
 * $processor = ProcessConditionalData::make($data);
 *
 * // Multiple conditions - only execute if all pass
 * if ($condition1 && $condition2 && $condition3) {
 *     $result = $processor->get(); // Executes only if all conditions true
 * }
 * @example
 * // ============================================
 * // Example 25: Lazy Batch Processing
 * // ============================================
 * class ProcessBatch extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $items): array
 *     {
 *         // Expensive batch processing
 *         return array_map(fn($item) => processItem($item), $items);
 *     }
 * }
 *
 * // Usage:
 * $batch = ProcessBatch::make($largeArray);
 * // Processing hasn't started
 *
 * // Only process if batch is actually needed
 * if ($shouldProcessBatch) {
 *     $processed = $batch->get();
 *     // Use processed items
 * }
 */
trait AsLazy
{
    // This is a marker trait - the actual lazy evaluation is handled by LazyDecorator
    // via the LazyDesignPattern and ActionManager
}
