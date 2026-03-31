<?php

namespace App\Actions\Concerns;

/**
 * Warns when deprecated actions are used.
 *
 * This trait is a marker that enables automatic deprecation warnings via DeprecatedDecorator.
 * When an action uses AsDeprecated, DeprecatedDesignPattern recognizes it and
 * ActionManager wraps the action with DeprecatedDecorator.
 *
 * How it works:
 * 1. Action uses AsDeprecated trait (marker)
 * 2. DeprecatedDesignPattern recognizes the trait
 * 3. ActionManager wraps action with DeprecatedDecorator
 * 4. When handle() is called, the decorator:
 *    - Logs a deprecation warning with full context
 *    - Triggers PHP E_USER_DEPRECATED in local/testing environments
 *    - Executes the action normally
 *    - Returns the result
 *
 * Features:
 * - Automatic deprecation warning logging
 * - PHP deprecation notices in development
 * - Configurable deprecation messages
 * - Removal version tracking
 * - Stack trace logging for debugging
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Benefits:
 * - Smooth migration path for deprecated actions
 * - Clear warnings for developers using old APIs
 * - Version tracking for removal planning
 * - No trait conflicts
 * - Composable with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * DeprecatedDecorator, which automatically wraps actions and adds deprecation warnings.
 * This follows the same pattern as AsLock, AsLogger, AsMetrics, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getDeprecationMessage()` method to customize warning message
 * - Implement `getRemovalVersion()` method to specify when action will be removed
 *
 * @example
 * // ============================================
 * // Example 1: Basic Deprecation
 * // ============================================
 * class OldAction extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Old implementation
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'Use NewAction instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 *
 * // Logs deprecation warning when action is used
 * OldAction::run();
 * @example
 * // ============================================
 * // Example 2: Deprecation with Migration Path
 * // ============================================
 * class LegacyCreateUser extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(array $data): User
 *     {
 *         // Old way of creating users
 *         return User::create($data);
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'Use CreateUser action instead. LegacyCreateUser will be removed.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '3.0';
 *     }
 * }
 *
 * // Usage - logs warning but still works
 * $user = LegacyCreateUser::run(['name' => 'John', 'email' => 'john@example.com']);
 * @example
 * // ============================================
 * // Example 3: Deprecation with Alternative
 * // ============================================
 * class OldProcessOrder extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Old processing logic
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'Use ProcessOrder::run() instead. This method uses deprecated logic.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.5';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Deprecation Without Removal Version
 * // ============================================
 * class TemporaryAction extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Temporary implementation
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This is a temporary action. Use the permanent solution when available.';
 *     }
 *     // No getRemovalVersion() - removal date unknown
 * }
 * @example
 * // ============================================
 * // Example 5: Deprecation in API Endpoints
 * // ============================================
 * class OldApiEndpoint extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(Request $request): JsonResponse
 *     {
 *         // Old API logic
 *         return response()->json(['status' => 'ok']);
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This endpoint is deprecated. Use /api/v2/endpoint instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '3.0';
 *     }
 * }
 *
 * // API consumers will see deprecation warnings in logs
 * @example
 * // ============================================
 * // Example 6: Deprecation with Feature Flag
 * // ============================================
 * class OldFeature extends Actions
 * {
 *     use AsDeprecated;
 *     use AsFeatureFlagged;
 *
 *     public function handle(): void
 *     {
 *         // Old feature implementation
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This feature is deprecated. Use NewFeature instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Deprecation in Scheduled Tasks
 * // ============================================
 * class OldScheduledTask extends Actions
 * {
 *     use AsDeprecated;
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Old scheduled task
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This scheduled task is deprecated. Use NewScheduledTask instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Deprecation with Migration Guide
 * // ============================================
 * class OldDataFormat extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(array $oldFormat): array
 *     {
 *         // Process old data format
 *         return $oldFormat;
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'Old data format is deprecated. See migration guide: /docs/migration/v2';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Deprecation in Background Jobs
 * // ============================================
 * class OldJob extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Old job logic
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This job is deprecated. Use NewJob instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 *
 * // Queue the deprecated job - warnings logged in queue worker
 * OldJob::dispatch();
 * @example
 * // ============================================
 * // Example 10: Deprecation with Version Check
 * // ============================================
 * class VersionSpecificAction extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Version-specific implementation
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         $currentVersion = config('app.version');
 *         return "This action is deprecated in v{$currentVersion}. Use NewAction instead.";
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: Deprecation in Commands
 * // ============================================
 * class OldCommand extends Actions
 * {
 *     use AsDeprecated;
 *     use AsCommand;
 *
 *     public function handle(): void
 *     {
 *         // Old command logic
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This command is deprecated. Use "php artisan new:command" instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Deprecation with Replacement
 * // ============================================
 * class OldNotification extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         // Old notification logic
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'Use SendNotification::run() instead. OldNotification is deprecated.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Deprecation in Tests
 * // ============================================
 * class OldTestHelper extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Old test helper
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'Use NewTestHelper in tests. OldTestHelper is deprecated.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 *
 * // In tests - deprecation warnings help identify outdated test code
 * test('uses old helper', function () {
 *     OldTestHelper::run(); // Logs deprecation warning
 * });
 * @example
 * // ============================================
 * // Example 14: Deprecation with Environment Check
 * // ============================================
 * class EnvironmentSpecificAction extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Environment-specific logic
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         $env = app()->environment();
 *         return "This action is deprecated in {$env}. Use NewAction instead.";
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Deprecation in Controllers
 * // ============================================
 * class OldControllerAction extends Actions
 * {
 *     use AsDeprecated;
 *     use AsController;
 *
 *     public function handle(Request $request): Response
 *     {
 *         // Old controller logic
 *         return response()->json(['status' => 'ok']);
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This controller action is deprecated. Use NewControllerAction instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Deprecation with Date-Based Removal
 * // ============================================
 * class TemporaryFeature extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(): void
 *     {
 *         // Temporary feature
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This feature is deprecated and will be removed on 2024-12-31.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0'; // Or could return date-based version
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Deprecation in Event Listeners
 * // ============================================
 * class OldEventListener extends Actions
 * {
 *     use AsDeprecated;
 *     use AsListener;
 *
 *     public function handle(Event $event): void
 *     {
 *         // Old event listener logic
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This event listener is deprecated. Use NewEventListener instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Deprecation with Breaking Changes
 * // ============================================
 * class OldApiVersion extends Actions
 * {
 *     use AsDeprecated;
 *
 *     public function handle(Request $request): JsonResponse
 *     {
 *         // Old API version logic
 *         return response()->json(['data' => 'old format']);
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'API v1 is deprecated. Migrate to v2. Breaking changes: see /docs/migration';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '3.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Deprecation in Middleware
 * // ============================================
 * class OldMiddleware extends Actions
 * {
 *     use AsDeprecated;
 *     use AsMiddleware;
 *
 *     public function handle($request, $next)
 *     {
 *         // Old middleware logic
 *         return $next($request);
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This middleware is deprecated. Use NewMiddleware instead.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Deprecation with Usage Tracking
 * // ============================================
 * class TrackedDeprecatedAction extends Actions
 * {
 *     use AsDeprecated;
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Deprecated action
 *     }
 *
 *     protected function getDeprecationMessage(): string
 *     {
 *         return 'This action is deprecated. Usage is being tracked for removal planning.';
 *     }
 *
 *     protected function getRemovalVersion(): string
 *     {
 *         return '2.0';
 *     }
 * }
 *
 * // Combines deprecation warnings with metrics tracking
 */
trait AsDeprecated
{
    // This is a marker trait - the actual deprecation warning functionality is handled by DeprecatedDecorator
    // via the DeprecatedDesignPattern and ActionManager
}
