<?php

namespace App\Actions\Concerns;

/**
 * Requires specific capability(ies) before action execution.
 *
 * This trait is a marker that enables automatic capability checking via RequiresCapabilityDecorator.
 * When an action uses AsRequiresCapability, RequiresCapabilityDesignPattern recognizes it and
 * ActionManager wraps the action with RequiresCapabilityDecorator.
 *
 * How it works:
 * 1. Action uses AsRequiresCapability trait (marker)
 * 2. RequiresCapabilityDesignPattern recognizes the trait
 * 3. ActionManager wraps action with RequiresCapabilityDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets required capabilities from action
 *    - Gets current user and team
 *    - Checks if user has required capability(ies) via RolesService
 *    - Supports AND/OR logic
 *    - Throws 403 if unauthorized
 *    - Executes action if authorized
 *
 * Benefits:
 * - Automatic capability checking
 * - Team context awareness
 * - Multiple capability support (AND/OR logic)
 * - Custom error handling
 * - Works with RolesService
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * RequiresCapabilityDecorator, which automatically wraps actions and checks capabilities.
 * This follows the same pattern as AsRequiresRole, AsPermission, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getRequiredCapabilities(...$arguments)` to specify required capabilities
 * - Set `$requiredCapabilities` property as array of capability codes
 * - Implement `getRequireAllCapabilities()` or set `$requireAllCapabilities` for AND logic
 * - Implement `handleUnauthorizedCapability(string $message)` for custom error handling
 *
 * Capability Checking:
 * - Default: OR logic (user needs ANY capability)
 * - With requireAll: AND logic (user needs ALL capabilities)
 * - Uses RolesService::hasCapability() for checking
 * - Team owners automatically have all capabilities
 *
 * @example
 * // ============================================
 * // Example 1: Basic Capability Requirement
 * // ============================================
 * class EditProjectAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     public function handle(Project $project): void
 *     {
 *         // Edit project logic
 *     }
 *
 *     protected function getRequiredCapabilities(): array
 *     {
 *         return ['projects:edit'];
 *     }
 * }
 *
 * // User must have 'projects:edit' capability
 * @example
 * // ============================================
 * // Example 2: Property-Based Capabilities
 * // ============================================
 * class ViewReportsAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['reports:view', 'analytics:view'];
 *
 *     public function handle(): void
 *     {
 *         // View reports logic
 *     }
 * }
 *
 * // User needs ANY of these capabilities (OR logic)
 * @example
 * // ============================================
 * // Example 3: AND Logic (Require All)
 * // ============================================
 * class ComplexAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['projects:edit', 'teams:manage'];
 *     protected bool $requireAllCapabilities = true;
 *
 *     public function handle(): void
 *     {
 *         // User must have BOTH capabilities
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Method-Based AND Logic
 * // ============================================
 * class MethodBasedAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['projects:edit', 'teams:manage'];
 *
 *     protected function getRequireAllCapabilities(): bool
 *     {
 *         return true; // Require ALL capabilities
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Dynamic Capabilities
 * // ============================================
 * class DynamicCapabilityAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     public function handle(Resource $resource): void
 *     {
 *         // Resource logic
 *     }
 *
 *     protected function getRequiredCapabilities(...$arguments): array
 *     {
 *         $resource = $arguments[0] ?? null;
 *
 *         // Different capabilities for different resource types
 *         if ($resource && $resource->type === 'sensitive') {
 *             return ['resources:edit:sensitive', 'admin:access'];
 *         }
 *
 *         return ['resources:edit'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Custom Error Handling
 * // ============================================
 * class CustomErrorAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['admin:access'];
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function handleUnauthorizedCapability(string $message): void
 *     {
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Insufficient capability',
 *                 'message' => $message,
 *                 'required_capabilities' => $this->requiredCapabilities,
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
 * // Example 7: Combining with Role Check
 * // ============================================
 * class RoleAndCapabilityAction extends Actions
 * {
 *     use AsRequiresRole;
 *     use AsRequiresCapability;
 *
 *     protected array $requiredRoles = ['Admin'];
 *     protected array $requiredCapabilities = ['projects:edit'];
 *
 *     public function handle(): void
 *     {
 *         // Requires role AND capability
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Module-Specific Capabilities
 * // ============================================
 * class ModuleAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['module:projects:edit'];
 *
 *     public function handle(): void
 *     {
 *         // Module-specific logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Multiple Capabilities (OR)
 * // ============================================
 * class FlexibleAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = [
 *         'projects:edit',
 *         'projects:admin',
 *         'admin:all',
 *     ];
 *
 *     public function handle(): void
 *     {
 *         // User needs ANY of these capabilities
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Combining with Billing
 * // ============================================
 * class CapabilityAndBillingAction extends Actions
 * {
 *     use AsRequiresCapability;
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredCapabilities = ['projects:edit'];
 *
 *     protected function getRequiredFeatures(): array
 *     {
 *         return ['advanced-projects'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Requires capability AND billing feature
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: Create Project Capability
 * // ============================================
 * class CreateProjectAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['projects:create'];
 *
 *     public function handle(Team $team, array $data): Project
 *     {
 *         return Project::create($data);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Delete Project Capability
 * // ============================================
 * class DeleteProjectAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['projects:delete'];
 *
 *     public function handle(Project $project): void
 *     {
 *         $project->delete();
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: View Sites Capability
 * // ============================================
 * class ViewSitesAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['sites:view'];
 *
 *     public function handle(Team $team): Collection
 *     {
 *         return $team->sites;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Manage Roles Capability
 * // ============================================
 * class ManageRolesAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['roles:manage'];
 *
 *     public function handle(Team $team, Role $role, array $data): void
 *     {
 *         $role->update($data);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Multiple Capabilities for Complex Action
 * // ============================================
 * class ComplexProjectAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['projects:edit', 'projects:manage', 'teams:manage'];
 *     protected bool $requireAllCapabilities = true;
 *
 *     public function handle(Project $project): void
 *     {
 *         // User must have ALL capabilities
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Module Capabilities
 * // ============================================
 * class ModuleSpecificAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     protected array $requiredCapabilities = ['module:analytics:view'];
 *
 *     public function handle(): array
 *     {
 *         return ['analytics' => 'data'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Resource-Specific Capabilities
 * // ============================================
 * class ResourceAction extends Actions
 * {
 *     use AsRequiresCapability;
 *
 *     public function handle(Resource $resource): void
 *     {
 *         // Resource logic
 *     }
 *
 *     protected function getRequiredCapabilities(...$arguments): array
 *     {
 *         $resource = $arguments[0] ?? null;
 *
 *         // Different capabilities based on resource type
 *         return match($resource->type ?? 'default') {
 *             'sensitive' => ['resources:edit:sensitive'],
 *             'public' => ['resources:edit'],
 *             default => ['resources:view'],
 *         };
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Combining Multiple Checks
 * // ============================================
 * class MultiCheckAction extends Actions
 * {
 *     use AsRequiresCapability;
 *     use AsRequiresRole;
 *     use AsRequiresPlan;
 *
 *     protected array $requiredCapabilities = ['projects:edit'];
 *     protected array $requiredRoles = ['Admin'];
 *     protected array $requiredPlans = ['professional'];
 *
 *     public function handle(): void
 *     {
 *         // All checks must pass
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Capability with Logging
 * // ============================================
 * class LoggedCapabilityAction extends Actions
 * {
 *     use AsRequiresCapability;
 *     use AsLogger;
 *
 *     protected array $requiredCapabilities = ['admin:access'];
 *
 *     public function handle(): void
 *     {
 *         // Capability check + execution logging
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Capability with Lifecycle
 * // ============================================
 * class LifecycleCapabilityAction extends Actions
 * {
 *     use AsRequiresCapability;
 *     use AsLifecycle;
 *
 *     protected array $requiredCapabilities = ['projects:edit'];
 *
 *     public function handle(Project $project): void
 *     {
 *         // Project logic
 *     }
 *
 *     protected function beforeHandle(Project $project): void
 *     {
 *         // Called after capability check passes
 *     }
 * }
 */
trait AsRequiresCapability
{
    // This is a marker trait - the actual capability checking is handled by RequiresCapabilityDecorator
    // via the RequiresCapabilityDesignPattern and ActionManager
}
