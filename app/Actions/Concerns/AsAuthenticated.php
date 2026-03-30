<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Requires authentication before action execution.
 *
 * This trait is a marker that enables automatic authentication checks via AuthenticatedDecorator.
 * When an action uses AsAuthenticated, AuthenticatedDesignPattern recognizes it and
 * ActionManager wraps the action with AuthenticatedDecorator.
 *
 * How it works:
 * 1. Action uses AsAuthenticated trait (marker)
 * 2. AuthenticatedDesignPattern recognizes the trait
 * 3. ActionManager wraps action with AuthenticatedDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets authentication guard
 *    - Checks authentication using Laravel's Auth
 *    - If authenticated, executes the action
 *    - If unauthenticated, calls handleUnauthenticated()
 *
 * Features:
 * - Automatic authentication checks before execution
 * - Configurable authentication guard
 * - Custom redirect routes for unauthenticated users
 * - Custom unauthenticated handling
 * - Works with Laravel's Auth system
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Prevents unauthenticated access
 * - Centralized authentication logic
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - User profile updates
 * - Protected resource access
 * - User-specific operations
 * - API endpoints requiring authentication
 * - Dashboard actions
 * - Account management
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * AuthenticatedDecorator, which automatically wraps actions and adds authentication.
 * This follows the same pattern as AsDebounced, AsAuthorized, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getAuthGuard()` method to customize guard (default: config('auth.defaults.guard'))
 * - Set `authGuard` property to customize guard
 * - Set `getAuthRedirectRoute()` method to customize redirect route (default: 'login')
 * - Implement `handleUnauthenticated()` for custom unauthenticated handling
 *
 * @example
 * // ============================================
 * // Example 1: Basic Authentication
 * // ============================================
 * class UpdateProfile extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(array $data): User
 *     {
 *         $user = auth()->user();
 *         $user->update($data);
 *
 *         return $user->fresh();
 *     }
 * }
 *
 * // Usage
 * UpdateProfile::run(['name' => 'John Doe']);
 * // Automatically redirects to login if not authenticated
 * @example
 * // ============================================
 * // Example 2: Custom Guard (API)
 * // ============================================
 * class GetUserData extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(): array
 *     {
 *         $user = auth()->user();
 *
 *         return [
 *             'id' => $user->id,
 *             'name' => $user->name,
 *             'email' => $user->email,
 *         ];
 *     }
 *
 *     protected function getAuthGuard(): string
 *     {
 *         return 'api';
 *     }
 * }
 *
 * // Usage
 * GetUserData::run();
 * // Checks authentication using 'api' guard
 * // Returns 401 JSON response if unauthenticated
 * @example
 * // ============================================
 * // Example 3: Custom Redirect Route
 * // ============================================
 * class AccessDashboard extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(): array
 *     {
 *         return [
 *             'stats' => DashboardStats::get(),
 *             'recent_activity' => Activity::recent(),
 *         ];
 *     }
 *
 *     protected function getAuthRedirectRoute(): string
 *     {
 *         return 'auth.login';
 *     }
 * }
 *
 * // Usage
 * AccessDashboard::run();
 * // Redirects to 'auth.login' route if not authenticated
 * @example
 * // ============================================
 * // Example 4: Custom Unauthenticated Handling
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(array $data): Post
 *     {
 *         return Post::create([
 *             'user_id' => auth()->id(),
 *             'title' => $data['title'],
 *             'content' => $data['content'],
 *         ]);
 *     }
 *
 *     protected function handleUnauthenticated(): void
 *     {
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'message' => 'You must be logged in to create posts.',
 *                 'code' => 'UNAUTHENTICATED',
 *             ], 401)->send();
 *             exit;
 *         }
 *
 *         // Redirect with flash message
 *         redirect()->route('login')
 *             ->with('error', 'Please log in to create posts.')
 *             ->send();
 *         exit;
 *     }
 * }
 *
 * // Usage
 * CreatePost::run(['title' => 'My Post', 'content' => 'Content here']);
 * // Custom unauthenticated handling with flash message
 * @example
 * // ============================================
 * // Example 5: Using Property for Guard
 * // ============================================
 * class ApiAction extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     protected string $authGuard = 'api';
 *
 *     public function handle(): array
 *     {
 *         return ['user' => auth()->user()];
 *     }
 * }
 *
 * // Usage
 * ApiAction::run();
 * // Uses 'api' guard from property
 * @example
 * // ============================================
 * // Example 6: Multiple Guards Support
 * // ============================================
 * class AdminAction extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(): void
 *     {
 *         // Admin-only action
 *     }
 *
 *     protected function getAuthGuard(): string
 *     {
 *         return 'admin'; // Custom admin guard
 *     }
 *
 *     protected function getAuthRedirectRoute(): string
 *     {
 *         return 'admin.login';
 *     }
 * }
 *
 * // Usage
 * AdminAction::run();
 * // Checks 'admin' guard, redirects to 'admin.login' if unauthenticated
 * @example
 * // ============================================
 * // Example 7: Account Management
 * // ============================================
 * class ChangePassword extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(string $currentPassword, string $newPassword): void
 *     {
 *         $user = auth()->user();
 *
 *         if (! Hash::check($currentPassword, $user->password)) {
 *             throw new \InvalidArgumentException('Current password is incorrect');
 *         }
 *
 *         $user->update(['password' => Hash::make($newPassword)]);
 *     }
 * }
 *
 * // Usage
 * ChangePassword::run($currentPassword, $newPassword);
 * // Requires authentication before password change
 * @example
 * // ============================================
 * // Example 8: User Preferences
 * // ============================================
 * class UpdatePreferences extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(array $preferences): User
 *     {
 *         $user = auth()->user();
 *         $user->update(['preferences' => $preferences]);
 *
 *         return $user->fresh();
 *     }
 * }
 *
 * // Usage
 * UpdatePreferences::run(['theme' => 'dark', 'notifications' => true]);
 * // Updates user preferences, requires authentication
 * @example
 * // ============================================
 * // Example 9: Protected Resource Access
 * // ============================================
 * class ViewPrivateDocument extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(Document $document): Document
 *     {
 *         // Document is already loaded, just return it
 *         // Authentication ensures only logged-in users can access
 *         return $document;
 *     }
 *
 *     protected function getAuthRedirectRoute(): string
 *     {
 *         return 'documents.login';
 *     }
 * }
 *
 * // Usage
 * ViewPrivateDocument::run($document);
 * // Requires authentication to view document
 * @example
 * // ============================================
 * // Example 10: API Token Authentication
 * // ============================================
 * class GetApiData extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(): array
 *     {
 *         $user = auth()->user();
 *
 *         return [
 *             'api_key' => $user->apiKey,
 *             'usage' => $user->apiUsage,
 *             'limits' => $user->apiLimits,
 *         ];
 *     }
 *
 *     protected function getAuthGuard(): string
 *     {
 *         return 'api';
 *     }
 *
 *     protected function handleUnauthenticated(): void
 *     {
 *         // API always returns JSON
 *         response()->json([
 *             'error' => 'Unauthenticated',
 *             'message' => 'Valid API token required',
 *         ], 401)->send();
 *         exit;
 *     }
 * }
 *
 * // Usage
 * GetApiData::run();
 * // Requires API token authentication, returns JSON 401 if missing
 * @example
 * // ============================================
 * // Example 11: Session-Based Authentication
 * // ============================================
 * class AddToCart extends Actions
 * {
 *     use AsAuthenticated;
 *
 *     public function handle(Product $product, int $quantity): CartItem
 *     {
 *         $user = auth()->user();
 *
 *         return CartItem::create([
 *             'user_id' => $user->id,
 *             'product_id' => $product->id,
 *             'quantity' => $quantity,
 *         ]);
 *     }
 *
 *     protected function getAuthGuard(): string
 *     {
 *         return 'web'; // Session-based authentication
 *     }
 *
 *     protected function getAuthRedirectRoute(): string
 *     {
 *         return 'cart.login';
 *     }
 * }
 *
 * // Usage
 * AddToCart::run($product, 2);
 * // Requires web session authentication, redirects to cart login if needed
 * @example
 * // ============================================
 * // Example 12: Composed with Other Decorators
 * // ============================================
 * class SecureAction extends Actions
 * {
 *     use AsAuthenticated;
 *     use AsAuthorized;
 *
 *     public function handle(Resource $resource): void
 *     {
 *         // Process resource
 *         $resource->process();
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'process';
 *     }
 *
 *     protected function getAuthorizationArguments(Resource $resource): array
 *     {
 *         return [$resource];
 *     }
 * }
 *
 * // Usage
 * SecureAction::run($resource);
 * // First checks authentication, then checks authorization
 * // Both decorators work together
 */
trait AsAuthenticated
{
    // This is a marker trait - the actual authentication functionality is handled by AuthenticatedDecorator
    // via the AuthenticatedDesignPattern and ActionManager
}
