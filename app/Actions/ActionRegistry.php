<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Collection;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Action Registry - Discover and manage actions.
 *
 * Provides discovery, categorization, and management of all actions
 * in the application.
 *
 * @example
 * // Discover all actions in default path (app/Actions)
 * $allActions = ActionRegistry::discover();
 * // Returns: Collection of all action class names
 * @example
 * // Discover actions in custom paths
 * $actions = ActionRegistry::discover([
 *     app_path('Actions'),
 *     app_path('Modules/Orders/Actions'),
 * ]);
 * @example
 * // Get all discovered actions
 * $actions = ActionRegistry::all();
 * @example
 * // Get actions by trait
 * use App\Actions\Concerns\AsAuthenticated;
 * use App\Actions\Concerns\AsCachedResult;
 *
 * $authenticatedActions = ActionRegistry::getByTrait(AsAuthenticated::class);
 * $cachedActions = ActionRegistry::getByTrait(AsCachedResult::class);
 * @example
 * // Tag actions for categorization
 * ActionRegistry::tag(ProcessOrder::class, ['payment', 'critical', 'order']);
 * ActionRegistry::tag(SendEmail::class, ['notification', 'email']);
 *
 * // Get actions by tag
 * $paymentActions = ActionRegistry::getByTag('payment');
 * $criticalActions = ActionRegistry::getByTag('critical');
 * @example
 * // Get action metadata
 * $metadata = ActionRegistry::getMetadata(ProcessOrder::class);
 * // Returns: [
 * //     'class' => 'App\Actions\ProcessOrder',
 * //     'name' => 'ProcessOrder',
 * //     'namespace' => 'App\Actions',
 * //     'traits' => ['AsAuthenticated', 'AsAuthorized', ...],
 * //     'methods' => ['handle', 'getAuthorizationAbility', ...],
 * //     'has_handle' => true,
 * //     'handle_params' => [
 * //         ['name' => 'order', 'type' => 'Order', 'optional' => false],
 * //     ],
 * // ]
 * @example
 * // Search actions by name or namespace
 * $results = ActionRegistry::search('Order');
 * // Finds: ProcessOrder, ValidateOrder, CancelOrder, etc.
 *
 * $results = ActionRegistry::search('Payment');
 * // Finds: ProcessPayment, RefundPayment, etc.
 * @example
 * // Get dependencies for an action
 * $dependencies = ActionRegistry::getDependencies(ProcessOrder::class);
 * // Returns: ['App\Actions\ValidateOrder', 'App\Actions\CheckInventory']
 *
 * // Get actions that depend on a specific action
 * $dependents = ActionRegistry::getDependents(ValidateOrder::class);
 * // Returns: ['App\Actions\ProcessOrder', 'App\Actions\UpdateOrder']
 * @example
 * // Register dependencies manually (usually done automatically by AsDependent)
 * ActionRegistry::registerDependencies(ProcessOrder::class, [
 *     ValidateOrder::class,
 *     CheckInventory::class,
 * ]);
 * @example
 * // Clear the registry cache
 * ActionRegistry::clear();
 */
class ActionRegistry
{
    /** @var Collection<int, string>|null */
    protected static ?Collection $actions = null;

    /** @var array<string, array<int, string>> */
    protected static array $tags = [];

    /** @var array<string, array<int, string>> */
    protected static array $dependencies = [];

    /**
     * Discover all actions in the given paths.
     *
     * @param  array<int, string>|string|null  $paths  Paths to scan (default: app/Actions)
     * @return Collection<int, string> Collection of action class names
     */
    public static function discover(string|array|null $paths = null): Collection
    {
        $paths = $paths ?? [app_path('Actions')];
        $paths = is_array($paths) ? $paths : [$paths];

        $actions = collect();

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            try {
                $actions = $actions->merge(static::classesIn($path));
            } catch (\Exception $e) {
                // Skip this path if there's an error
                continue;
            }
        }

        static::$actions = $actions->unique()->values();

        return static::$actions;
    }

    /**
     * Concrete Actions subclasses declared under $path.
     *
     * Replaces the previous Lody::classes() scan — lorisleiva/lody was never a
     * declared dependency, so discover() threw on every call and the catch above
     * silently returned an empty collection. Symfony Finder ships with Laravel.
     *
     * @return Collection<int, class-string>
     */
    protected static function classesIn(string $path): Collection
    {
        $classes = collect();

        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $class = static::classNameFor($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Actions::class)) {
                continue;
            }

            $classes->push($class);
        }

        return $classes;
    }

    /**
     * Map an absolute file path to its PSR-4 class name via composer's app/ root.
     *
     * @return class-string|null
     */
    protected static function classNameFor(string $file): ?string
    {
        $appPath = realpath(app_path());

        if ($appPath === false || ! str_starts_with($file, $appPath)) {
            return null;
        }

        $relative = trim(substr($file, strlen($appPath)), DIRECTORY_SEPARATOR);
        $class = app()->getNamespace().str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative
        );

        /** @var class-string */
        return $class;
    }

    /**
     * Get all discovered actions.
     *
     * @return Collection<int, string>
     */
    public static function all(): Collection
    {
        if (static::$actions === null) {
            static::discover();
        }

        return static::$actions ?? collect();
    }

    /**
     * Get actions that use a specific trait.
     *
     * @param  string  $trait  Trait class name
     * @return Collection<int, string> Action class names
     */
    public static function getByTrait(string $trait): Collection
    {
        return static::all()->filter(function (string $actionClass) use ($trait) {
            return in_array($trait, class_uses_recursive($actionClass));
        });
    }

    /**
     * Get actions by tag.
     *
     * @param  string  $tag  Tag name
     * @return Collection<int, string> Action class names
     */
    public static function getByTag(string $tag): Collection
    {
        /** @var array<int, string> $taggedActions */
        $taggedActions = static::$tags[$tag] ?? [];

        return collect($taggedActions);
    }

    /**
     * Tag an action.
     *
     * @param  string  $actionClass  Action class name
     * @param  array<int, string>|string  $tags  Tag(s) to assign
     */
    public static function tag(string $actionClass, string|array $tags): void
    {
        $tags = is_array($tags) ? $tags : [$tags];

        foreach ($tags as $tag) {
            if (! isset(static::$tags[$tag])) {
                static::$tags[$tag] = [];
            }

            if (! in_array($actionClass, static::$tags[$tag])) {
                static::$tags[$tag][] = $actionClass;
            }
        }
    }

    /**
     * Get dependencies for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string> Array of dependency action class names
     */
    public static function getDependencies(string $actionClass): array
    {
        return static::$dependencies[$actionClass] ?? [];
    }

    /**
     * Register dependencies for an action.
     *
     * @param  string  $actionClass  Action class name
     * @param  array<int, string>|string  $dependencies  Dependency action class names
     */
    public static function registerDependencies(string $actionClass, string|array $dependencies): void
    {
        static::$dependencies[$actionClass] = is_array($dependencies) ? $dependencies : [$dependencies];
    }

    /**
     * Get actions that depend on a specific action.
     *
     * @param  string  $actionClass  Action class name
     * @return Collection<int, string> Action class names that depend on this action
     */
    public static function getDependents(string $actionClass): Collection
    {
        return collect(static::$dependencies)
            ->filter(fn (array $deps) => in_array($actionClass, $deps))
            ->keys();
    }

    /**
     * Get action metadata.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Metadata about the action
     */
    public static function getMetadata(string $actionClass): array
    {
        if (! class_exists($actionClass)) {
            return [];
        }

        $reflection = new \ReflectionClass($actionClass);
        $traits = class_uses_recursive($actionClass);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        return [
            'class' => $actionClass,
            'name' => class_basename($actionClass),
            'namespace' => $reflection->getNamespaceName(),
            'traits' => array_values($traits),
            'methods' => array_map(fn ($m) => $m->getName(), $methods),
            'has_handle' => $reflection->hasMethod('handle'),
            'handle_params' => $reflection->hasMethod('handle')
                ? array_map(fn ($p) => [
                    'name' => $p->getName(),
                    'type' => $p->getType()?->getName(),
                    'optional' => $p->isOptional(),
                ], $reflection->getMethod('handle')->getParameters())
                : [],
        ];
    }

    /**
     * Search actions by name or namespace.
     *
     * @param  string  $query  Search query
     * @return Collection<int, string> Matching action class names
     */
    public static function search(string $query): Collection
    {
        return static::all()->filter(function (string $actionClass) use ($query) {
            $name = class_basename($actionClass);
            $namespace = (new \ReflectionClass($actionClass))->getNamespaceName();

            return stripos($name, $query) !== false
                || stripos($namespace, $query) !== false
                || stripos($actionClass, $query) !== false;
        });
    }

    /**
     * Clear the registry cache.
     */
    public static function clear(): void
    {
        static::$actions = null;
        static::$tags = [];
        static::$dependencies = [];
    }
}
