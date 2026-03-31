<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Action Profiler - Advanced profiling for actions.
 *
 * Provides detailed profiling capabilities including execution traces,
 * memory profiling, and performance analysis.
 *
 * @example
 * // Profile an action execution
 * $profileId = ActionProfiler::start(ProcessOrder::class, [$order]);
 *
 * try {
 *     $result = ProcessOrder::run($order);
 *     $profile = ActionProfiler::stop($profileId, $result);
 * } catch (\Throwable $e) {
 *     $profile = ActionProfiler::stop($profileId, null, $e);
 * }
 *
 * // $profile contains:
 * // [
 * //     'action' => 'App\Actions\ProcessOrder',
 * //     'arguments' => [...],
 * //     'result' => $result,
 * //     'exception' => null,
 * //     'duration_ms' => 234.5,
 * //     'memory_used_mb' => 2.5,
 * //     'peak_memory_mb' => 3.0,
 * //     'trace' => [...],
 * //     'timestamp' => '2024-01-15T10:30:00Z',
 * // ]
 * @example
 * // Get profile by ID
 * $profile = ActionProfiler::getProfile($profileId);
 * @example
 * // Get all profiles for an action
 * $profiles = ActionProfiler::getProfilesForAction(ProcessOrder::class);
 * @example
 * // Get summary statistics
 * $summary = ActionProfiler::getSummary(ProcessOrder::class);
 * // Returns: [
 * //     'total_profiles' => 150,
 * //     'avg_duration_ms' => 234.5,
 * //     'min_duration_ms' => 120.0,
 * //     'max_duration_ms' => 450.0,
 * //     'avg_memory_mb' => 2.5,
 * //     'total_memory_mb' => 375.0,
 * // ]
 * @example
 * // Clear all profiles
 * ActionProfiler::clear();
 */
class ActionProfiler
{
    protected static array $profiles = [];

    protected static array $activeProfiles = [];

    /**
     * Start profiling an action.
     *
     * @param  string  $actionClass  Action class name
     * @param  array  $arguments  Arguments being passed
     * @return string Profile ID
     */
    public static function start(string $actionClass, array $arguments = []): string
    {
        $profileId = uniqid('profile_', true);
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        static::$activeProfiles[$profileId] = [
            'action' => $actionClass,
            'arguments' => $arguments,
            'start_time' => $startTime,
            'start_memory' => $startMemory,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
        ];

        return $profileId;
    }

    /**
     * Stop profiling and record the profile.
     *
     * @param  string  $profileId  Profile ID from start()
     * @param  mixed  $result  Result from the action
     * @param  \Throwable|null  $exception  Exception if action failed
     * @return array<string, mixed> Profile data
     */
    public static function stop(string $profileId, mixed $result = null, ?\Throwable $exception = null): array
    {
        if (! isset(static::$activeProfiles[$profileId])) {
            throw new \InvalidArgumentException("Profile ID '{$profileId}' not found");
        }

        $profile = static::$activeProfiles[$profileId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $profileData = [
            'action' => $profile['action'],
            'arguments' => $profile['arguments'],
            'result' => $result,
            'exception' => $exception ? get_class($exception) : null,
            'exception_message' => $exception?->getMessage(),
            'duration_ms' => ($endTime - $profile['start_time']) * 1000,
            'memory_used_mb' => ($endMemory - $profile['start_memory']) / 1024 / 1024,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
            'trace' => $profile['trace'],
            'timestamp' => now()->toIso8601String(),
        ];

        static::$profiles[$profileId] = $profileData;
        unset(static::$activeProfiles[$profileId]);

        return $profileData;
    }

    /**
     * Get a profile by ID.
     *
     * @param  string  $profileId  Profile ID
     * @return array<string, mixed>|null Profile data
     */
    public static function getProfile(string $profileId): ?array
    {
        return static::$profiles[$profileId] ?? null;
    }

    /**
     * Get all profiles.
     *
     * @return array<string, array> All profile data
     */
    public static function getAllProfiles(): array
    {
        return static::$profiles;
    }

    /**
     * Get profiles for a specific action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<array> Profile data for the action
     */
    public static function getProfilesForAction(string $actionClass): array
    {
        return array_filter(static::$profiles, fn ($profile) => $profile['action'] === $actionClass);
    }

    /**
     * Clear all profiles.
     */
    public static function clear(): void
    {
        static::$profiles = [];
        static::$activeProfiles = [];
    }

    /**
     * Get summary statistics.
     *
     * @param  string|null  $actionClass  Optional action class to filter by
     * @return array<string, mixed> Summary statistics
     */
    public static function getSummary(?string $actionClass = null): array
    {
        $profiles = $actionClass
            ? static::getProfilesForAction($actionClass)
            : static::$profiles;

        if (empty($profiles)) {
            return [
                'total_profiles' => 0,
                'avg_duration_ms' => 0,
                'avg_memory_mb' => 0,
            ];
        }

        $durations = array_column($profiles, 'duration_ms');
        $memories = array_column($profiles, 'memory_used_mb');

        return [
            'total_profiles' => count($profiles),
            'avg_duration_ms' => array_sum($durations) / count($durations),
            'min_duration_ms' => min($durations),
            'max_duration_ms' => max($durations),
            'avg_memory_mb' => array_sum($memories) / count($memories),
            'total_memory_mb' => array_sum($memories),
        ];
    }
}
