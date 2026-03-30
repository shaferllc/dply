<?php

namespace App\Actions\Concerns;

/**
 * Requires specific team role(s) before action execution.
 *
 * This trait is a marker that enables automatic role checking via RequiresRoleDecorator.
 * When an action uses AsRequiresRole, RequiresRoleDesignPattern recognizes it and
 * ActionManager wraps the action with RequiresRoleDecorator.
 *
 * How it works:
 * 1. Action uses AsRequiresRole trait (marker)
 * 2. RequiresRoleDesignPattern recognizes the trait
 * 3. ActionManager wraps action with RequiresRoleDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets required roles from action
 *    - Gets current user and team
 *    - Checks if user has required role(s) in team (OR logic)
 *    - Throws 403 if unauthorized
 *    - Executes action if authorized
 *
 * Benefits:
 * - Automatic role checking
 * - Team context awareness
 * - Multiple role support (OR logic)
 * - Custom error handling
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * RequiresRoleDecorator, which automatically wraps actions and checks roles.
 * This follows the same pattern as AsPermission, AsLogger, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getRequiredRoles(...$arguments)` to specify required roles
 * - Set `$requiredRoles` property as array of role names
 * - Implement `handleUnauthorizedRole(string $message)` for custom error handling
 *
 * Role Checking:
 * - Uses OR logic: user needs ANY of the specified roles
 * - Team owners automatically have access
 * - Checks user's role in current team context
 *
 * @example
 * // ============================================
 * // Example 1: Basic Role Requirement
 * // ============================================
 * class AdminOnlyAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     public function handle(): void
 *     {
 *         // Admin-only logic
 *     }
 *
 *     protected function getRequiredRoles(): array
 *     {
 *         return ['Admin', 'Owner'];
 *     }
 * }
 *
 * // User must have Admin or Owner role in current team
 * @example
 * // ============================================
 * // Example 2: Property-Based Roles
 * // ============================================
 * class EditorAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Editor', 'Admin', 'Owner'];
 *
 *     public function handle(): void
 *     {
 *         // Editor logic
 *     }
 * }
 *
 * // Roles defined via property
 * @example
 * // ============================================
 * // Example 3: Dynamic Role Based on Arguments
 * // ============================================
 * class DynamicRoleAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     public function handle(Project $project): void
 *     {
 *         // Project logic
 *     }
 *
 *     protected function getRequiredRoles(...$arguments): array
 *     {
 *         $project = $arguments[0] ?? null;
 *
 *         // Different roles for different project types
 *         if ($project && $project->is_private) {
 *             return ['Owner', 'Admin']; // Stricter for private projects
 *         }
 *
 *         return ['Editor', 'Admin', 'Owner']; // Standard roles
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Custom Error Handling
 * // ============================================
 * class CustomErrorAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function handleUnauthorizedRole(string $message): void
 *     {
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Insufficient role',
 *                 'message' => $message,
 *                 'required_roles' => $this->requiredRoles,
 *             ], 403)->send();
 *             exit;
 *         }
 *
 *         redirect()->route('unauthorized')->with('error', $message)->send();
 *         exit;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Combining with Other Decorators
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsRequiresRole;
 *     use AsRequiresCapability;
 *     use AsLogger;
 *
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function getRequiredCapabilities(): array
 *     {
 *         return ['projects:edit'];
 *     }
 * }
 *
 * // All decorators work together:
 * // - RequiresRoleDecorator checks role
 * // - RequiresCapabilityDecorator checks capability
 * // - LoggerDecorator tracks execution
 * @example
 * // ============================================
 * // Example 6: Team-Specific Role Check
 * // ============================================
 * class TeamSpecificAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Team-specific logic
 *     }
 *
 *     protected function getRequiredRoles(...$arguments): array
 *     {
 *         $team = $arguments[0] ?? null;
 *
 *         // Different roles for different teams
 *         if ($team && $team->is_enterprise) {
 *             return ['Owner', 'Admin'];
 *         }
 *
 *         return ['Editor', 'Admin', 'Owner'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Single Role Requirement
 * // ============================================
 * class OwnerOnlyAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Owner'];
 *
 *     public function handle(): void
 *     {
 *         // Owner-only logic
 *     }
 * }
 *
 * // Only team owners can execute
 * @example
 * // ============================================
 * // Example 8: Multiple Roles (OR Logic)
 * // ============================================
 * class MultiRoleAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Editor', 'Admin', 'Owner', 'Manager'];
 *
 *     public function handle(): void
 *     {
 *         // User needs ANY of these roles
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Role with Subscription Check
 * // ============================================
 * class RoleAndSubscriptionAction extends Actions
 * {
 *     use AsRequiresRole;
 *     use AsRequiresSubscription;
 *
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // Requires both role AND subscription
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Role with Plan Check
 * // ============================================
 * class RoleAndPlanAction extends Actions
 * {
 *     use AsRequiresRole;
 *     use AsRequiresPlan;
 *
 *     protected array $requiredRoles = ['Admin'];
 *
 *     protected function getRequiredPlans(): array
 *     {
 *         return ['professional', 'enterprise'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Requires role AND plan
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: Delete Team Action
 * // ============================================
 * class DeleteTeamAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Owner'];
 *
 *     public function handle(Team $team): void
 *     {
 *         // Only team owners can delete teams
 *         $team->delete();
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Manage Team Settings
 * // ============================================
 * class UpdateTeamSettingsAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Admin', 'Owner'];
 *
 *     public function handle(Team $team, array $settings): void
 *     {
 *         $team->update($settings);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Assign Roles to Users
 * // ============================================
 * class AssignRoleAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Admin', 'Owner'];
 *
 *     public function handle(User $user, Role $role): void
 *     {
 *         // Only admins/owners can assign roles
 *         $user->roles()->attach($role);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: View Team Analytics
 * // ============================================
 * class ViewTeamAnalyticsAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Admin', 'Owner', 'Manager'];
 *
 *     public function handle(Team $team): array
 *     {
 *         return $team->getAnalytics();
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Export Team Data
 * // ============================================
 * class ExportTeamDataAction extends Actions
 * {
 *     use AsRequiresRole;
 *     use AsJob;
 *
 *     protected array $requiredRoles = ['Admin', 'Owner'];
 *
 *     public function handle(Team $team): void
 *     {
 *         // Export team data (queued job)
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Invite Team Members
 * // ============================================
 * class InviteTeamMemberAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Admin', 'Owner'];
 *
 *     public function handle(Team $team, string $email, Role $role): void
 *     {
 *         // Only admins/owners can invite members
 *         $team->invite($email, $role);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Remove Team Members
 * // ============================================
 * class RemoveTeamMemberAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Admin', 'Owner'];
 *
 *     public function handle(Team $team, User $user): void
 *     {
 *         // Only admins/owners can remove members
 *         $team->removeMember($user);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Create Team Projects
 * // ============================================
 * class CreateTeamProjectAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Editor', 'Admin', 'Owner'];
 *
 *     public function handle(Team $team, array $projectData): Project
 *     {
 *         return $team->projects()->create($projectData);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Manage Billing
 * // ============================================
 * class ManageTeamBillingAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Owner'];
 *
 *     public function handle(Team $team, array $billingData): void
 *     {
 *         // Only owners can manage billing
 *         $team->updateBilling($billingData);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Archive Team
 * // ============================================
 * class ArchiveTeamAction extends Actions
 * {
 *     use AsRequiresRole;
 *
 *     protected array $requiredRoles = ['Owner'];
 *
 *     public function handle(Team $team): void
 *     {
 *         // Only owners can archive teams
 *         $team->archive();
 *     }
 * }
 */
trait AsRequiresRole
{
    // This is a marker trait - the actual role checking is handled by RequiresRoleDecorator
    // via the RequiresRoleDesignPattern and ActionManager
}
