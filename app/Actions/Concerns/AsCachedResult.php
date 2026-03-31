<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\CachedResultDecorator;
use Illuminate\Support\Facades\Cache;

/**
 * Caches action results based on input hash.
 *
 * This trait is a marker that enables automatic result caching via CachedResultDecorator.
 * When an action uses AsCachedResult, CachedResultDesignPattern recognizes it and
 * ActionManager wraps the action with CachedResultDecorator.
 *
 * How it works:
 * 1. Action uses AsCachedResult trait (marker)
 * 2. CachedResultDesignPattern recognizes the trait
 * 3. ActionManager wraps action with CachedResultDecorator
 * 4. When handle() is called, the decorator:
 *    - Generates cache key from arguments
 *    - Checks cache for existing result
 *    - If cached, returns cached result
 *    - If not cached, executes action and caches result
 *
 * Features:
 * - Automatic result caching
 * - Configurable cache TTL
 * - Custom cache key generation
 * - Cache invalidation methods
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Improves performance for expensive operations
 * - Reduces load on external services
 * - Prevents duplicate calculations
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - Expensive calculations
 * - External API calls
 * - Database queries
 * - File processing
 * - Data transformation
 * - Report generation
 * - Search results
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * CachedResultDecorator, which automatically wraps actions and adds result caching.
 * This follows the same pattern as AsDebounced, AsCostTracked, AsCompensatable, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `cacheTtl` property or `getCacheTtl()` method (default: 3600 seconds)
 * - Optionally implement `getCacheKey(...$arguments)` to customize cache key generation
 *
 * @example
 * // ============================================
 * // Example 1: Basic Result Caching
 * // ============================================
 * class ExpensiveCalculation extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(int $input): int
 *     {
 *         // Expensive operation
 *         return $input * 2;
 *     }
 *
 *     // Optional: customize cache TTL
 *     protected function getCacheTtl(): int
 *     {
 *         return 3600; // 1 hour
 *     }
 * }
 *
 * // Results are automatically cached and reused for identical inputs
 * ExpensiveCalculation::run(5); // Executes and caches
 * ExpensiveCalculation::run(5); // Returns cached result
 * @example
 * // ============================================
 * // Example 2: Custom Cache Key
 * // ============================================
 * class UserDataLookup extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(User $user): array
 *     {
 *         // Expensive database query
 *         return $user->load('posts', 'comments')->toArray();
 *     }
 *
 *     protected function getCacheKey(User $user): string
 *     {
 *         // Use user ID in cache key
 *         return 'user_data:'.$user->id;
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 1800; // 30 minutes
 *     }
 * }
 *
 * // Cache key based on user ID
 * @example
 * // ============================================
 * // Example 3: External API Caching
 * // ============================================
 * class FetchWeatherData extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(string $city): array
 *     {
 *         return Http::get("https://api.weather.com/{$city}")->json();
 *     }
 *
 *     protected function getCacheKey(string $city): string
 *     {
 *         return 'weather:'.strtolower($city);
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 300; // 5 minutes (weather changes frequently)
 *     }
 * }
 *
 * // Caches weather API responses for 5 minutes
 * @example
 * // ============================================
 * // Example 4: Database Query Caching
 * // ============================================
 * class GetPopularProducts extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(int $limit = 10): array
 *     {
 *         return Product::where('status', 'active')
 *             ->orderBy('sales_count', 'desc')
 *             ->limit($limit)
 *             ->get()
 *             ->toArray();
 *     }
 *
 *     protected function getCacheKey(int $limit): string
 *     {
 *         return 'popular_products:'.$limit;
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 600; // 10 minutes
 *     }
 * }
 *
 * // Caches popular products query results
 * @example
 * // ============================================
 * // Example 5: Property-Based Configuration
 * // ============================================
 * class ConfigurableCachedAction extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public int $cacheTtl = 7200; // 2 hours
 *
 *     public function handle(string $data): string
 *     {
 *         // Process data
 *         return strtoupper($data);
 *     }
 * }
 *
 * // Uses property for cache TTL
 * @example
 * // ============================================
 * // Example 6: Cache Invalidation
 * // ============================================
 * class CachedUserProfile extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(User $user): array
 *     {
 *         return $user->load('profile', 'settings')->toArray();
 *     }
 *
 *     protected function getCacheKey(User $user): string
 *     {
 *         return 'user_profile:'.$user->id;
 *     }
 * }
 *
 * // Usage:
 * $profile = CachedUserProfile::run($user);
 *
 * // Later, when user updates profile:
 * $user->profile->update(['bio' => 'New bio']);
 * CachedUserProfile::forgetCache($user); // Clear cache
 *
 * // Next call will fetch fresh data
 * $profile = CachedUserProfile::run($user);
 * @example
 * // ============================================
 * // Example 7: Multiple Arguments Caching
 * // ============================================
 * class SearchProducts extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(string $query, array $filters = []): array
 *     {
 *         return Product::search($query)
 *             ->where($filters)
 *             ->get()
 *             ->toArray();
 *     }
 *
 *     protected function getCacheKey(string $query, array $filters = []): string
 *     {
 *         // Include both query and filters in cache key
 *         return 'product_search:'.md5($query.serialize($filters));
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 300; // 5 minutes
 *     }
 * }
 *
 * // Caches search results with filters
 * @example
 * // ============================================
 * // Example 8: Long-Running Process Caching
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(string $reportType, array $dateRange): string
 *     {
 *         // Expensive report generation
 *         $data = $this->collectData($reportType, $dateRange);
 *         $report = $this->formatReport($data);
 *
 *         return $report;
 *     }
 *
 *     protected function getCacheKey(string $reportType, array $dateRange): string
 *     {
 *         return 'report:'.$reportType.':'.md5(serialize($dateRange));
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 86400; // 24 hours (reports don't change often)
 *     }
 * }
 *
 * // Caches generated reports for 24 hours
 * @example
 * // ============================================
 * // Example 9: File Processing Caching
 * // ============================================
 * class ProcessImage extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(string $imagePath, array $options): string
 *     {
 *         // Expensive image processing
 *         $image = Image::make($imagePath);
 *         $image->resize($options['width'], $options['height']);
 *         $processedPath = storage_path('processed/'.basename($imagePath));
 *         $image->save($processedPath);
 *
 *         return $processedPath;
 *     }
 *
 *     protected function getCacheKey(string $imagePath, array $options): string
 *     {
 *         // Cache based on file hash and options
 *         $fileHash = md5_file($imagePath);
 *         return 'processed_image:'.$fileHash.':'.md5(serialize($options));
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 604800; // 7 days
 *     }
 * }
 *
 * // Caches processed images based on file content and options
 * @example
 * // ============================================
 * // Example 10: Data Transformation Caching
 * // ============================================
 * class TransformData extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(array $data, string $format): array
 *     {
 *         // Expensive data transformation
 *         return match($format) {
 *             'json' => $this->toJson($data),
 *             'xml' => $this->toXml($data),
 *             'csv' => $this->toCsv($data),
 *             default => $data,
 *         };
 *     }
 *
 *     protected function getCacheKey(array $data, string $format): string
 *     {
 *         // Cache based on data hash and format
 *         return 'transformed:'.md5(serialize($data)).':'.$format;
 *     }
 * }
 *
 * // Caches transformed data by format
 * @example
 * // ============================================
 * // Example 11: Environment-Specific TTL
 * // ============================================
 * class EnvironmentAwareCache extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(): array
 *     {
 *         // Action logic
 *         return ['data' => 'result'];
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return match(app()->environment()) {
 *             'production' => 3600,  // 1 hour in production
 *             'staging' => 600,      // 10 minutes in staging
 *             'local' => 60,         // 1 minute in local
 *             default => 300,         // 5 minutes default
 *         };
 *     }
 * }
 *
 * // Different cache TTL per environment
 * @example
 * // ============================================
 * // Example 12: Cache with Tags (Redis)
 * // ============================================
 * class TaggedCacheAction extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(Product $product): array
 *     {
 *         return $product->load('reviews', 'images')->toArray();
 *     }
 *
 *     protected function getCacheKey(Product $product): string
 *     {
 *         return 'product:'.$product->id;
 *     }
 *
 *     // Note: Cache tags require Redis cache driver
 *     // The decorator automatically uses tags if available
 * }
 *
 * // Cache can be invalidated by tags when product is updated
 * @example
 * // ============================================
 * // Example 13: Conditional Caching
 * // ============================================
 * class ConditionalCacheAction extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(User $user): array
 *     {
 *         return $user->load('posts')->toArray();
 *     }
 *
 *     protected function getCacheKey(User $user): string
 *     {
 *         return 'user_posts:'.$user->id;
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         // Don't cache for admin users (always fresh data)
 *         if (auth()->user()?->isAdmin()) {
 *             return 0; // No caching
 *         }
 *
 *         return 3600; // Cache for regular users
 *     }
 * }
 *
 * // Conditional caching based on user role
 * @example
 * // ============================================
 * // Example 14: Clear All Cache
 * // ============================================
 * class ClearableCacheAction extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'result'];
 *     }
 * }
 *
 * // Usage:
 * ClearableCacheAction::run(); // Caches result
 *
 * // Later, clear all cached results for this action:
 * ClearableCacheAction::clearAllCache();
 *
 * // Note: clearAllCache() uses cache tags if available (Redis),
 * // otherwise clears entire cache (use with caution)
 */
trait AsCachedResult
{
    /**
     * Reference to the cached result decorator (injected by decorator).
     */
    protected ?CachedResultDecorator $_cachedResultDecorator = null;

    /**
     * Set the cached result decorator reference.
     *
     * Called by CachedResultDecorator to inject itself.
     */
    public function setCachedResultDecorator(CachedResultDecorator $decorator): void
    {
        $this->_cachedResultDecorator = $decorator;
    }

    /**
     * Get the cached result decorator.
     */
    protected function getCachedResultDecorator(): ?CachedResultDecorator
    {
        return $this->_cachedResultDecorator;
    }

    /**
     * Forget cached result for specific arguments.
     *
     * @param  mixed  ...$arguments  The arguments used to generate the cache key
     */
    public static function forgetCache(...$arguments): void
    {
        $instance = static::make();
        $decorator = $instance->getCachedResultDecorator();

        if ($decorator) {
            $decorator->forgetCache(...$arguments);
        } else {
            // Fallback: create temporary decorator to generate key
            $tempDecorator = app(CachedResultDecorator::class, ['action' => $instance]);
            $tempDecorator->forgetCache(...$arguments);
        }
    }

    /**
     * Clear all cached results for this action.
     *
     * Note: This requires a cache driver that supports tags (e.g., Redis).
     * If tags are not available, this will clear the entire cache (use with caution).
     */
    public static function clearAllCache(): void
    {
        $instance = static::make();
        $decorator = $instance->getCachedResultDecorator();

        if ($decorator) {
            $decorator->clearAllCache();
        } else {
            // Fallback: create temporary decorator to clear cache
            $tempDecorator = app(CachedResultDecorator::class, ['action' => $instance]);
            $tempDecorator->clearAllCache();
        }
    }
}
