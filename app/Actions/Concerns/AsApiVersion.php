<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\ApiVersionDecorator;

/**
 * Handles API versioning for actions.
 *
 * This trait is a marker that enables automatic API versioning via ApiVersionDecorator.
 * When an action uses AsApiVersion, ApiVersionDesignPattern recognizes it and
 * ActionManager wraps the action with ApiVersionDecorator.
 *
 * How it works:
 * 1. Action uses AsApiVersion trait (marker)
 * 2. ApiVersionDesignPattern recognizes the trait
 * 3. ActionManager wraps action with ApiVersionDecorator
 * 4. When handle() is called, the decorator:
 *    - Detects API version from headers, route, or query parameters
 *    - Sets the version on the action
 *    - Executes the action
 *    - Actions can call getApiVersion() during execution
 *
 * Features:
 * - Automatic API version detection
 * - Multiple detection sources (headers, route, query)
 * - Configurable default version
 * - Custom version detection logic
 * - Version available during action execution
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait with delegation)
 *
 * Benefits:
 * - API versioning support
 * - Backward compatibility
 * - Multiple API versions in one action
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - API endpoints with versioning
 * - Backward compatibility
 * - Gradual API migration
 * - Multiple API versions
 * - Version-specific responses
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ApiVersionDecorator, which automatically wraps actions and adds versioning.
 * Unlike pure marker traits like AsDebounced, this trait includes delegation
 * methods (getApiVersion, setApiVersion) because actions need to call these
 * methods during execution to determine which version-specific logic to use.
 *
 * Configuration:
 * - Set `detectApiVersion()` method to customize version detection
 * - Set `getDefaultApiVersion()` method to customize default version
 * - Set `defaultApiVersion` property to customize default version
 *
 * @example
 * // ============================================
 * // Example 1: Basic API Versioning
 * // ============================================
 * class GetUsers extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => $this->handleV1(),
 *             'v2' => $this->handleV2(),
 *             default => $this->handleV2(),
 *         };
 *     }
 *
 *     protected function handleV1(): array
 *     {
 *         return User::all()->map(fn($u) => [
 *             'id' => $u->id,
 *             'name' => $u->name,
 *         ])->toArray();
 *     }
 *
 *     protected function handleV2(): array
 *     {
 *         return User::all()->map(fn($u) => [
 *             'id' => $u->id,
 *             'name' => $u->name,
 *             'email' => $u->email,
 *             'created_at' => $u->created_at,
 *         ])->toArray();
 *     }
 * }
 *
 * // Usage
 * // Header: API-Version: v1
 * GetUsers::run(); // Returns v1 format
 *
 * // Header: API-Version: v2
 * GetUsers::run(); // Returns v2 format
 * @example
 * // ============================================
 * // Example 2: Custom Version Detection
 * // ============================================
 * class GetProducts extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1', 'v2' => $this->handleLegacy(),
 *             'v3' => $this->handleCurrent(),
 *             default => $this->handleCurrent(),
 *         };
 *     }
 *
 *     protected function detectApiVersion(): string
 *     {
 *         // Check custom header
 *         $version = request()->header('X-API-Version');
 *
 *         if ($version) {
 *             return $version;
 *         }
 *
 *         // Check subdomain
 *         $subdomain = explode('.', request()->getHost())[0];
 *         if (preg_match('/^v\d+$/', $subdomain)) {
 *             return $subdomain;
 *         }
 *
 *         return $this->getDefaultApiVersion();
 *     }
 *
 *     protected function getDefaultApiVersion(): string
 *     {
 *         return 'v3';
 *     }
 *
 *     protected function handleLegacy(): array
 *     {
 *         // Legacy implementation
 *         return [];
 *     }
 *
 *     protected function handleCurrent(): array
 *     {
 *         // Current implementation
 *         return [];
 *     }
 * }
 *
 * // Usage
 * // Header: X-API-Version: v2
 * GetProducts::run(); // Uses legacy handler
 * @example
 * // ============================================
 * // Example 3: Route-Based Versioning
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $version = $this->getApiVersion();
 *
 *         if ($version === 'v1') {
 *             // V1: Only allow name updates
 *             $user->update(['name' => $data['name'] ?? $user->name]);
 *         } else {
 *             // V2+: Allow all fields
 *             $user->update($data);
 *         }
 *
 *         return $user->fresh();
 *     }
 * }
 *
 * // Route: /api/v1/users/{user}
 * // Route: /api/v2/users/{user}
 * // Version detected from route parameter
 * @example
 * // ============================================
 * // Example 4: Query Parameter Versioning
 * // ============================================
 * class SearchProducts extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(string $query): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => $this->searchV1($query),
 *             'v2' => $this->searchV2($query),
 *             default => $this->searchV2($query),
 *         };
 *     }
 *
 *     protected function searchV1(string $query): array
 *     {
 *         // Simple search
 *         return Product::where('name', 'like', "%{$query}%")->get()->toArray();
 *     }
 *
 *     protected function searchV2(string $query): array
 *     {
 *         // Advanced search with ranking
 *         return Product::search($query)->get()->toArray();
 *     }
 * }
 *
 * // Usage
 * // GET /api/products/search?q=laptop&version=v2
 * SearchProducts::run('laptop'); // Uses v2 search
 * @example
 * // ============================================
 * // Example 5: Accept Header Versioning
 * // ============================================
 * class GetOrders extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => Order::all()->toArray(),
 *             'v2' => OrderResource::collection(Order::all())->resolve(),
 *             default => OrderResource::collection(Order::all())->resolve(),
 *         };
 *     }
 * }
 *
 * // Usage
 * // Header: Accept: application/json; version=v2
 * GetOrders::run(); // Returns v2 format
 * @example
 * // ============================================
 * // Example 6: Version-Specific Validation
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(array $data): Post
 *     {
 *         $version = $this->getApiVersion();
 *
 *         // Different validation per version
 *         if ($version === 'v1') {
 *             $validated = validator($data, ['title' => 'required'])->validate();
 *         } else {
 *             $validated = validator($data, [
 *                 'title' => 'required|min:3',
 *                 'content' => 'required',
 *                 'tags' => 'array',
 *             ])->validate();
 *         }
 *
 *         return Post::create($validated);
 *     }
 * }
 *
 * // Usage
 * CreatePost::run(['title' => 'My Post']); // Validation depends on version
 * @example
 * // ============================================
 * // Example 7: Setting Version Programmatically
 * // ============================================
 * class ProcessData extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(array $data): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => $this->processV1($data),
 *             'v2' => $this->processV2($data),
 *             default => $this->processV2($data),
 *         };
 *     }
 *
 *     protected function processV1(array $data): array
 *     {
 *         // V1 processing logic
 *         return $data;
 *     }
 *
 *     protected function processV2(array $data): array
 *     {
 *         // V2 processing logic
 *         return $data;
 *     }
 * }
 *
 * // Usage
 * ProcessData::apiVersion('v1')->run($data); // Force v1
 * ProcessData::apiVersion('v2')->run($data); // Force v2
 * @example
 * // ============================================
 * // Example 8: Default Version Property
 * // ============================================
 * class GetSettings extends Actions
 * {
 *     use AsApiVersion;
 *
 *     protected string $defaultApiVersion = 'v2';
 *
 *     public function handle(): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => $this->getSettingsV1(),
 *             'v2' => $this->getSettingsV2(),
 *             default => $this->getSettingsV2(),
 *         };
 *     }
 *
 *     protected function getSettingsV1(): array
 *     {
 *         // V1 settings format
 *         return [];
 *     }
 *
 *     protected function getSettingsV2(): array
 *     {
 *         // V2 settings format
 *         return [];
 *     }
 * }
 *
 * // Usage
 * GetSettings::run(); // Defaults to v2 if no version specified
 * @example
 * // ============================================
 * // Example 9: Complex Version Logic
 * // ============================================
 * class CalculatePrice extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(Product $product, int $quantity): float
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => $product->price * $quantity, // Simple calculation
 *             'v2' => $this->calculateWithDiscounts($product, $quantity),
 *             'v3' => $this->calculateWithTaxes($product, $quantity),
 *             default => $this->calculateWithTaxes($product, $quantity),
 *         };
 *     }
 *
 *     protected function calculateWithDiscounts(Product $product, int $quantity): float
 *     {
 *         // Calculate with discounts
 *         return $product->price * $quantity;
 *     }
 *
 *     protected function calculateWithTaxes(Product $product, int $quantity): float
 *     {
 *         // Calculate with taxes
 *         return $product->price * $quantity;
 *     }
 * }
 *
 * // Usage
 * CalculatePrice::run($product, 5); // Calculation method depends on version
 * @example
 * // ============================================
 * // Example 10: Version-Specific Response Format
 * // ============================================
 * class GetUserProfile extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(User $user): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         if ($version === 'v1') {
 *             return [
 *                 'id' => $user->id,
 *                 'name' => $user->name,
 *             ];
 *         }
 *
 *         // V2+ includes more data
 *         return [
 *             'id' => $user->id,
 *             'name' => $user->name,
 *             'email' => $user->email,
 *             'avatar' => $user->avatar_url,
 *             'preferences' => $user->preferences,
 *             'metadata' => [
 *                 'created_at' => $user->created_at,
 *                 'updated_at' => $user->updated_at,
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage
 * GetUserProfile::run($user); // Response format depends on version
 * @example
 * // ============================================
 * // Example 11: Deprecation Warnings
 * // ============================================
 * class GetData extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         if ($version === 'v1') {
 *             // Add deprecation header
 *             response()->header('X-API-Deprecated', 'v1 will be removed on 2024-12-31');
 *         }
 *
 *         return match ($version) {
 *             'v1' => $this->getDataV1(),
 *             'v2' => $this->getDataV2(),
 *             default => $this->getDataV2(),
 *         };
 *     }
 *
 *     protected function getDataV1(): array
 *     {
 *         // V1 data format
 *         return [];
 *     }
 *
 *     protected function getDataV2(): array
 *     {
 *         // V2 data format
 *         return [];
 *     }
 * }
 *
 * // Usage
 * GetData::run(); // V1 responses include deprecation warning
 * @example
 * // ============================================
 * // Example 12: Version-Specific Permissions
 * // ============================================
 * class AccessResource extends Actions
 * {
 *     use AsApiVersion;
 *
 *     public function handle(Resource $resource): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         // V1: Basic access
 *         // V2+: Enhanced access with additional fields
 *         if ($version === 'v1') {
 *             return ['id' => $resource->id, 'name' => $resource->name];
 *         }
 *
 *         // V2+ requires additional permission
 *         if (! auth()->user()->can('view_enhanced', $resource)) {
 *             abort(403, 'Enhanced view requires additional permission');
 *         }
 *
 *         return [
 *             'id' => $resource->id,
 *             'name' => $resource->name,
 *             'details' => $resource->details,
 *             'metadata' => $resource->metadata,
 *         ];
 *     }
 * }
 *
 * // Usage
 * AccessResource::run($resource); // Permissions depend on version
 */
trait AsApiVersion
{
    /**
     * Reference to the API version decorator (injected by decorator).
     */
    protected ?ApiVersionDecorator $_apiVersionDecorator = null;

    /**
     * Set the API version decorator reference.
     *
     * Called by ApiVersionDecorator to inject itself.
     */
    public function setApiVersionDecorator(ApiVersionDecorator $decorator): void
    {
        $this->_apiVersionDecorator = $decorator;
    }

    /**
     * Get the API version decorator.
     */
    protected function getApiVersionDecorator(): ?ApiVersionDecorator
    {
        return $this->_apiVersionDecorator;
    }

    /**
     * Get the current API version.
     *
     * @return string The API version (e.g., 'v1', 'v2')
     */
    public function getApiVersion(): string
    {
        $decorator = $this->getApiVersionDecorator();
        if ($decorator) {
            return $decorator->getApiVersion();
        }

        // Fallback if decorator not available
        return 'v1';
    }

    /**
     * Set the API version programmatically.
     *
     * @param  string  $version  The API version to set
     * @return $this
     */
    public function setApiVersion(string $version): self
    {
        $decorator = $this->getApiVersionDecorator();
        if ($decorator) {
            $decorator->setApiVersion($version);
        }

        return $this;
    }

    /**
     * Create action instance with specific API version.
     *
     * @param  string  $version  The API version
     * @return static
     */
    public static function apiVersion(string $version): self
    {
        return static::make()->setApiVersion($version);
    }
}
