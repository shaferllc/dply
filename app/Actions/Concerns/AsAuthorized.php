<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Requires authorization before action execution.
 *
 * This trait is a marker that enables automatic authorization checks via AuthorizedDecorator.
 * When an action uses AsAuthorized, AuthorizedDesignPattern recognizes it and
 * ActionManager wraps the action with AuthorizedDecorator.
 *
 * How it works:
 * 1. Action uses AsAuthorized trait (marker)
 * 2. AuthorizedDesignPattern recognizes the trait
 * 3. ActionManager wraps action with AuthorizedDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets authorization ability and arguments
 *    - Checks authorization using Laravel's Gate
 *    - If authorized, executes the action
 *    - If unauthorized, calls handleUnauthorized()
 *
 * Features:
 * - Automatic authorization checks before execution
 * - Configurable authorization ability
 * - Custom authorization arguments
 * - Custom unauthorized handling
 * - Works with Laravel's Gate system
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Prevents unauthorized access
 * - Centralized authorization logic
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - Resource deletion
 * - Data modification
 * - Administrative actions
 * - User-specific operations
 * - Role-based access control
 * - Permission checks
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * AuthorizedDecorator, which automatically wraps actions and adds authorization.
 * This follows the same pattern as AsDebounced, AsLock, AsLogger, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getAuthorizationAbility()` method to customize ability name
 * - Set `getAuthorizationArguments(...$arguments)` method to customize arguments
 * - Implement `handleUnauthorized()` for custom unauthorized handling
 *
 * @example
 * // ============================================
 * // Example 1: Basic Authorization
 * // ============================================
 * class DeletePost extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Post $post): void
 *     {
 *         $post->delete();
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'delete';
 *     }
 *
 *     protected function getAuthorizationArguments(Post $post): array
 *     {
 *         return [$post];
 *     }
 * }
 *
 * // Usage
 * DeletePost::run($post);
 * // Automatically checks Gate::allows('delete', $post) before deletion
 * @example
 * // ============================================
 * // Example 2: Using Default Ability (Action Name)
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         $user->update($data);
 *     }
 *
 *     // No getAuthorizationAbility() - uses 'updateuser' (class basename)
 *     // No getAuthorizationArguments() - passes all arguments
 * }
 *
 * // Usage
 * UpdateUser::run($user, ['name' => 'John']);
 * // Checks Gate::allows('updateuser', $user, ['name' => 'John'])
 * @example
 * // ============================================
 * // Example 3: Custom Authorization Arguments
 * // ============================================
 * class TransferFunds extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Account $from, Account $to, float $amount): void
 *     {
 *         $from->withdraw($amount);
 *         $to->deposit($amount);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'transfer';
 *     }
 *
 *     protected function getAuthorizationArguments(Account $from, Account $to, float $amount): array
 *     {
 *         // Only check authorization for the source account
 *         return [$from];
 *     }
 * }
 *
 * // Usage
 * TransferFunds::run($fromAccount, $toAccount, 1000.00);
 * // Checks Gate::allows('transfer', $fromAccount)
 * @example
 * // ============================================
 * // Example 4: Custom Unauthorized Handling
 * // ============================================
 * class PublishArticle extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Article $article): void
 *     {
 *         $article->update(['published' => true]);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'publish';
 *     }
 *
 *     protected function getAuthorizationArguments(Article $article): array
 *     {
 *         return [$article];
 *     }
 *
 *     protected function handleUnauthorized(): void
 *     {
 *         // Custom handling for unauthorized access
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'message' => 'You do not have permission to publish articles.',
 *                 'code' => 'UNAUTHORIZED_PUBLISH',
 *             ], 403)->send();
 *             exit;
 *         }
 *
 *         // Redirect with flash message
 *         redirect()->back()->with('error', 'You do not have permission to publish articles.')->send();
 *         exit;
 *     }
 * }
 *
 * // Usage
 * PublishArticle::run($article);
 * // Custom unauthorized handling if user lacks 'publish' ability
 * @example
 * // ============================================
 * // Example 5: Multiple Authorization Arguments
 * // ============================================
 * class ShareDocument extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Document $document, User $user, string $permission): void
 *     {
 *         $document->shareWith($user, $permission);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'share';
 *     }
 *
 *     protected function getAuthorizationArguments(Document $document, User $user, string $permission): array
 *     {
 *         // Check authorization with document and permission level
 *         return [$document, $permission];
 *     }
 * }
 *
 * // Usage
 * ShareDocument::run($document, $user, 'read');
 * // Checks Gate::allows('share', $document, 'read')
 * @example
 * // ============================================
 * // Example 6: Role-Based Authorization
 * // ============================================
 * class ManageUsers extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(User $user, array $changes): void
 *     {
 *         $user->update($changes);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'manage-users';
 *     }
 *
 *     protected function getAuthorizationArguments(User $user): array
 *     {
 *         // Check if user can manage this specific user
 *         return [$user];
 *     }
 * }
 *
 * // Gate definition (in AuthServiceProvider):
 * // Gate::define('manage-users', function (User $currentUser, User $targetUser) {
 * //     return $currentUser->isAdmin() || $currentUser->id === $targetUser->id;
 * // });
 *
 * // Usage
 * ManageUsers::run($user, ['role' => 'admin']);
 * // Checks if current user can manage the target user
 * @example
 * // ============================================
 * // Example 7: Resource Ownership Check
 * // ============================================
 * class EditProfile extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         $user->update($data);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'edit';
 *     }
 *
 *     protected function getAuthorizationArguments(User $user): array
 *     {
 *         // Check if user owns the profile
 *         return [$user];
 *     }
 * }
 *
 * // Gate definition:
 * // Gate::define('edit', function (User $currentUser, User $targetUser) {
 * //     return $currentUser->id === $targetUser->id || $currentUser->isAdmin();
 * // });
 *
 * // Usage
 * EditProfile::run($user, ['bio' => 'New bio']);
 * // Only allows editing own profile or if admin
 * @example
 * // ============================================
 * // Example 8: Team-Based Authorization
 * // ============================================
 * class DeleteTeamMember extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Team $team, User $member): void
 *     {
 *         $team->members()->detach($member);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'remove-member';
 *     }
 *
 *     protected function getAuthorizationArguments(Team $team, User $member): array
 *     {
 *         // Check authorization with team and member
 *         return [$team, $member];
 *     }
 * }
 *
 * // Gate definition:
 * // Gate::define('remove-member', function (User $user, Team $team, User $member) {
 * //     return $team->isOwner($user) || ($team->isAdmin($user) && $user->id !== $member->id);
 * // });
 *
 * // Usage
 * DeleteTeamMember::run($team, $member);
 * // Checks if user can remove member from team
 * @example
 * // ============================================
 * // Example 9: Conditional Authorization Arguments
 * // ============================================
 * class ApproveRequest extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Request $request): void
 *     {
 *         $request->update(['status' => 'approved']);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'approve';
 *     }
 *
 *     protected function getAuthorizationArguments(Request $request): array
 *     {
 *         // Include request type in authorization check
 *         return [$request, $request->type];
 *     }
 * }
 *
 * // Gate definition:
 * // Gate::define('approve', function (User $user, Request $request, string $type) {
 * //     return match($type) {
 * //         'expense' => $user->canApproveExpenses(),
 * //         'leave' => $user->canApproveLeave(),
 * //         default => false,
 * //     };
 * // });
 *
 * // Usage
 * ApproveRequest::run($request);
 * // Checks authorization based on request type
 * @example
 * // ============================================
 * // Example 10: API Authorization
 * // ============================================
 * class CreateApiKey extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(User $user): ApiKey
 *     {
 *         return ApiKey::create(['user_id' => $user->id]);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'create-api-key';
 *     }
 *
 *     protected function getAuthorizationArguments(User $user): array
 *     {
 *         return [$user];
 *     }
 *
 *     protected function handleUnauthorized(): void
 *     {
 *         // API-specific unauthorized response
 *         response()->json([
 *             'error' => 'Unauthorized',
 *             'message' => 'You do not have permission to create API keys.',
 *         ], 403)->send();
 *         exit;
 *     }
 * }
 *
 * // Usage
 * CreateApiKey::run($user);
 * // Returns JSON 403 if unauthorized
 * @example
 * // ============================================
 * // Example 11: Policy-Based Authorization
 * // ============================================
 * class ArchiveProject extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Project $project): void
 *     {
 *         $project->update(['archived' => true]);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'archive';
 *     }
 *
 *     protected function getAuthorizationArguments(Project $project): array
 *     {
 *         // Uses project policy
 *         return [$project];
 *     }
 * }
 *
 * // ProjectPolicy:
 * // public function archive(User $user, Project $project): bool {
 * //     return $project->isOwner($user) || $user->isAdmin();
 * // }
 *
 * // Usage
 * ArchiveProject::run($project);
 * // Checks ProjectPolicy::archive() via Gate
 * @example
 * // ============================================
 * // Example 12: Complex Authorization with Multiple Checks
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsAuthorized;
 *
 *     public function handle(Order $order, PaymentMethod $method, float $amount): void
 *     {
 *         $order->processPayment($method, $amount);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'process-payment';
 *     }
 *
 *     protected function getAuthorizationArguments(Order $order, PaymentMethod $method, float $amount): array
 *     {
 *         // Check authorization with order and payment method
 *         return [$order, $method];
 *     }
 * }
 *
 * // Gate definition:
 * // Gate::define('process-payment', function (User $user, Order $order, PaymentMethod $method) {
 * //     return $order->user_id === $user->id
 * //         && $method->user_id === $user->id
 * //         && $order->status === 'pending';
 * // });
 *
 * // Usage
 * ProcessPayment::run($order, $method, 99.99);
 * // Checks multiple conditions before processing payment
 */
trait AsAuthorized
{
    // This is a marker trait - the actual authorization functionality is handled by AuthorizedDecorator
    // via the AuthorizedDesignPattern and ActionManager
}
