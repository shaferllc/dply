<?php

namespace App\Actions\Concerns;

/**
 * Automatically throttles action execution to limit concurrent runs.
 *
 * Uses the decorator pattern to automatically wrap actions and limit
 * concurrent executions. The ThrottleDecorator intercepts handle()
 * calls and prevents too many instances from running simultaneously.
 *
 * How it works:
 * 1. When an action uses AsThrottle, ThrottleDesignPattern recognizes it
 * 2. ActionManager wraps the action with ThrottleDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates a throttle key (based on action and arguments)
 *    - Checks current concurrent executions
 *    - Throws exception if max concurrent reached
 *    - Increments counter, executes action, then decrements
 *    - Adds throttle metadata to the result
 *
 * Benefits:
 * - Prevents resource exhaustion from too many concurrent executions
 * - Configurable max concurrent executions
 * - Configurable TTL for throttle keys
 * - Custom throttle key generation
 * - Throttle metadata in results
 * - Enable/disable per action
 * - Seamless integration with other decorators
 *
 * @example
 * // Basic usage - throttling happens automatically:
 * class ExpensiveOperation
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(): void
 *     {
 *         // Expensive operation - automatically throttled
 *     }
 * }
 *
 * // Usage:
 * $result = ExpensiveOperation::run();
 * // $result->_throttle = [
 * //     'max_concurrent' => 5,
 * //     'current' => 2,  // Concurrent executions at start
 * //     'enabled' => true,
 * // ];
 *
 * // Access throttle metadata:
 * $maxConcurrent = $result->_throttle['max_concurrent'] ?? null;
 * $currentConcurrent = $result->_throttle['current'] ?? 0;
 * $isEnabled = $result->_throttle['enabled'] ?? false;
 * @example
 * // Customize throttling via attributes (recommended):
 * use App\Actions\Attributes\ThrottleEnabled;
 * use App\Actions\Attributes\ThrottleMaxConcurrent;
 * use App\Actions\Attributes\ThrottleTtl;
 *
 * #[ThrottleEnabled(true)]      // Enable throttling
 * #[ThrottleMaxConcurrent(10)]  // Allow 10 concurrent executions
 * #[ThrottleTtl(600)]           // 10 minutes TTL for throttle keys
 * class ExpensiveOperation
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(): void
 *     {
 *         // Expensive operation
 *     }
 * }
 *
 * // Usage:
 * $result = ExpensiveOperation::run();
 * // $result->_throttle = [
 * //     'max_concurrent' => 10,
 * //     'current' => 1,
 * //     'enabled' => true,
 * // ];
 *
 * // If max concurrent is reached, throws RuntimeException:
 * // "Maximum concurrent executions (10) reached for this action"
 * @example
 * // Disable throttling via attribute:
 * use App\Actions\Attributes\ThrottleEnabled;
 *
 * #[ThrottleEnabled(false)] // Disable throttling for this action
 * class ExpensiveOperation
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(): void
 *     {
 *         // Expensive operation - no throttling
 *     }
 * }
 *
 * // Usage:
 * $result = ExpensiveOperation::run();
 * // $result->_throttle is not set when throttling is disabled
 * @example
 * // Customize throttling via methods (alternative to attributes):
 * class ExpensiveOperation
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(): void
 *     {
 *         // Expensive operation
 *     }
 *
 *     protected function getMaxConcurrent(): int
 *     {
 *         return 10; // Allow 10 concurrent executions
 *     }
 *
 *     protected function getThrottleTtl(): int
 *     {
 *         return 600; // 10 minutes TTL
 *     }
 * }
 *
 * // Usage:
 * $result = ExpensiveOperation::run();
 * // $result->_throttle = [
 * //     'max_concurrent' => 10,
 * //     'current' => 1,
 * //     'enabled' => true,
 * // ];
 * @example
 * // Custom throttle key generation based on arguments:
 * class ProcessUserData
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         // Process user-specific data
 *     }
 *
 *     protected function buildThrottleKey(array $arguments): string
 *     {
 *         // Throttle per user - each user gets their own throttle limit
 *         $user = $arguments[0] ?? null;
 *
 *         return $user
 *             ? "throttle:user:{$user->id}"
 *             : 'throttle:global';
 *     }
 *
 *     protected function getMaxConcurrent(): int
 *     {
 *         return 3; // 3 concurrent executions per user
 *     }
 * }
 *
 * // Usage:
 * // User 1 can have 3 concurrent executions
 * $result1 = ProcessUserData::run($user1, $data1);
 * $result2 = ProcessUserData::run($user1, $data2);
 * $result3 = ProcessUserData::run($user1, $data3);
 * // 4th call for user1 would throw exception
 *
 * // User 2 has separate throttle limit
 * $result4 = ProcessUserData::run($user2, $data4); // OK, different user
 * @example
 * // Real-world example: Using throttling with other decorators
 * use App\Actions\Attributes\ThrottleEnabled;
 * use App\Actions\Attributes\ThrottleMaxConcurrent;
 * use App\Actions\Attributes\ThrottleTtl;
 * use App\Actions\Attributes\TimeoutEnabled;
 * use App\Actions\Attributes\TimeoutSeconds;
 * use App\Actions\Attributes\TransactionAttempts;
 *
 * #[ThrottleEnabled(true)]
 * #[ThrottleMaxConcurrent(5)] // Allow max 5 concurrent executions
 * #[ThrottleTtl(300)] // 5 minutes TTL for throttle keys
 * #[TimeoutEnabled(true)]
 * #[TimeoutSeconds(30)] // 30 second timeout
 * #[TransactionAttempts(1)]
 * class CreateTag
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(Team $team, array $data): Tag
 *     {
 *         // Database operations that might be called concurrently
 *         $tag = Tag::findOrCreate($data['name']);
 *         $team->attachTag($tag);
 *
 *         return $tag;
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 *
 * // Access throttle metadata along with other decorator metadata:
 * // $tag->_throttle = ['max_concurrent' => 5, 'current' => 2, 'enabled' => true];
 * // $tag->_timeout = ['seconds' => 30, 'enforced' => true];
 * // $tag->_transaction = ['used' => true, 'attempts' => 1];
 * @example
 * // Dynamic throttling based on input or conditions:
 * class ProcessData
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function __construct(
 *         public string $priority = 'normal'
 *     ) {}
 *
 *     public function handle(array $data): array
 *     {
 *         // Process data
 *         return processData($data);
 *     }
 *
 *     protected function getMaxConcurrent(): int
 *     {
 *         // Higher priority gets more concurrent slots
 *         return match ($this->priority) {
 *             'high' => 20,   // 20 concurrent for high priority
 *             'normal' => 10, // 10 concurrent for normal priority
 *             'low' => 5,     // 5 concurrent for low priority
 *             default => 10,
 *         };
 *     }
 *
 *     protected function getThrottleTtl(): int
 *     {
 *         // Higher priority uses shorter TTL (faster recovery)
 *         return match ($this->priority) {
 *             'high' => 60,   // 1 minute for high priority
 *             'normal' => 300, // 5 minutes for normal priority
 *             'low' => 600,   // 10 minutes for low priority
 *             default => 300,
 *         };
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessData::make(priority: 'high')->handle($data);
 * // $result->_throttle = ['max_concurrent' => 20, 'current' => 1, 'enabled' => true];
 * @example
 * // Throttle per team/organization for multi-tenant scenarios:
 * class GenerateReport
 * {
 *     use AsAction;
 *     use AsThrottle;
 *
 *     public function handle(Team $team, array $options): Report
 *     {
 *         // Generate report for team
 *         return generateReport($team, $options);
 *     }
 *
 *     protected function buildThrottleKey(array $arguments): string
 *     {
 *         // Throttle per team - each team has independent limits
 *         $team = $arguments[0] ?? null;
 *
 *         return $team
 *             ? "throttle:report:team:{$team->id}"
 *             : 'throttle:report:global';
 *     }
 *
 *     protected function getMaxConcurrent(): int
 *     {
 *         return 2; // 2 concurrent reports per team
 *     }
 *
 *     protected function getThrottleTtl(): int
 *     {
 *         return 180; // 3 minutes TTL
 *     }
 * }
 *
 * // Usage:
 * // Team 1 can have 2 concurrent report generations
 * $report1 = GenerateReport::run($team1, $options1);
 * $report2 = GenerateReport::run($team1, $options2);
 * // 3rd call for team1 would throw exception
 *
 * // Team 2 has separate throttle limit
 * $report3 = GenerateReport::run($team2, $options3); // OK, different team
 */
trait AsThrottle
{
    //
}
