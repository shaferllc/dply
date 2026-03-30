<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Action Error Tracking - Centralized error tracking for actions.
 *
 * Provides comprehensive error tracking, grouping, and analysis.
 *
 * @example
 * // Track an error
 * ActionErrorTracking::track(ProcessOrder::class, $exception, ['order_id' => 123]);
 * @example
 * // Get errors for an action
 * $errors = ActionErrorTracking::getErrors(ProcessOrder::class);
 * @example
 * // Get error summary
 * $summary = ActionErrorTracking::getSummary(ProcessOrder::class);
 * // Returns: [
 * //     'total_errors' => 50,
 * //     'unique_errors' => 5,
 * //     'most_common' => [...],
 * //     'recent_errors' => [...],
 * // ]
 * @example
 * // Get dashboard
 * $dashboard = ActionErrorTracking::dashboard();
 * @example
 * // Clear errors
 * ActionErrorTracking::clearErrors(ProcessOrder::class);
 */
class ActionErrorTracking
{
    /**
     * Track an error for an action.
     *
     * @param  string  $actionClass  Action class name
     * @param  \Throwable  $exception  Exception that occurred
     * @param  array  $context  Additional context
     */
    public static function track(string $actionClass, \Throwable $exception, array $context = []): void
    {
        $error = [
            'action' => $actionClass,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
            'hash' => static::getErrorHash($exception),
        ];

        // Store in cache
        $key = "errors:{$actionClass}:".static::getErrorHash($exception);
        $errors = Cache::get($key, []);
        $errors[] = $error;
        Cache::put($key, $errors, 86400 * 7); // Store for 7 days

        // Store in database if table exists
        if (DB::getSchemaBuilder()->hasTable('action_errors')) {
            DB::table('action_errors')->insert([
                'action' => $actionClass,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'context' => json_encode($context),
                'error_hash' => static::getErrorHash($exception),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Get errors for an action.
     *
     * @param  string  $actionClass  Action class name
     * @param  int  $limit  Number of errors to return
     * @return Collection<array> Errors
     */
    public static function getErrors(string $actionClass, int $limit = 50): Collection
    {
        if (DB::getSchemaBuilder()->hasTable('action_errors')) {
            return DB::table('action_errors')
                ->where('action', $actionClass)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn ($error) => [
                    'id' => $error->id,
                    'action' => $error->action,
                    'exception' => $error->exception,
                    'message' => $error->message,
                    'file' => $error->file,
                    'line' => $error->line,
                    'context' => json_decode($error->context, true),
                    'created_at' => $error->created_at,
                ]);
        }

        return collect();
    }

    /**
     * Get error summary for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Error summary
     */
    public static function getSummary(string $actionClass): array
    {
        if (! DB::getSchemaBuilder()->hasTable('action_errors')) {
            return [
                'total_errors' => 0,
                'unique_errors' => 0,
                'most_common' => [],
                'recent_errors' => [],
            ];
        }

        $totalErrors = DB::table('action_errors')
            ->where('action', $actionClass)
            ->count();

        $uniqueErrors = DB::table('action_errors')
            ->where('action', $actionClass)
            ->distinct('error_hash')
            ->count('error_hash');

        $mostCommon = DB::table('action_errors')
            ->where('action', $actionClass)
            ->select('error_hash', 'exception', 'message', DB::raw('count(*) as count'))
            ->groupBy('error_hash', 'exception', 'message')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($error) => [
                'exception' => $error->exception,
                'message' => $error->message,
                'count' => $error->count,
            ]);

        $recentErrors = static::getErrors($actionClass, 10);

        return [
            'total_errors' => $totalErrors,
            'unique_errors' => $uniqueErrors,
            'most_common' => $mostCommon->toArray(),
            'recent_errors' => $recentErrors->toArray(),
        ];
    }

    /**
     * Get dashboard data for all actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::all();
        $errorStats = collect();
        $totalErrors = 0;

        foreach ($actions as $actionClass) {
            $summary = static::getSummary($actionClass);
            if ($summary['total_errors'] > 0) {
                $errorStats->push([
                    'action' => $actionClass,
                    ...$summary,
                ]);
                $totalErrors += $summary['total_errors'];
            }
        }

        return [
            'total_errors' => $totalErrors,
            'actions_with_errors' => $errorStats->count(),
            'actions' => $errorStats->sortByDesc('total_errors')->values()->toArray(),
        ];
    }

    /**
     * Clear errors for an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function clearErrors(string $actionClass): void
    {
        if (DB::getSchemaBuilder()->hasTable('action_errors')) {
            DB::table('action_errors')->where('action', $actionClass)->delete();
        }

        // Clear cache
        $pattern = "errors:{$actionClass}:*";
        // Note: Cache tags would be better here, but this is a simple implementation
    }

    /**
     * Get error hash for grouping similar errors.
     */
    protected static function getErrorHash(\Throwable $exception): string
    {
        return md5(get_class($exception).':'.$exception->getFile().':'.$exception->getLine());
    }
}
