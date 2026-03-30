<?php

namespace App\Actions\Helpers;

use App\Actions\Actions;
use App\Actions\Concerns\AsListener;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Lody\Lody;

/**
 * Auto-discovers and registers action listeners.
 *
 * Scans for all actions that use AsListener trait and automatically
 * registers them as event listeners based on their method signatures.
 */
class ListenerAutoDiscovery
{
    /**
     * Discover and register all action listeners.
     *
     * @param  string|array  $paths  Paths to scan for actions (default: app/Actions)
     * @return array<string, array<int, class-string>> Discovered event-to-listener mappings
     */
    public static function discover(string|array|null $paths = null): array
    {
        $paths = $paths ?? [app_path('Actions')];
        $paths = is_array($paths) ? $paths : [$paths];

        $mappings = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            try {
                $classes = Lody::classes($path)
                    ->whereInstanceOf(Actions::class)
                    ->whereUses(AsListener::class);

                foreach ($classes as $listenerClass) {
                    // Skip if class doesn't exist or can't be loaded
                    if (! class_exists($listenerClass)) {
                        continue;
                    }

                    $eventClasses = static::getEventClasses($listenerClass);

                    foreach ($eventClasses as $eventClass) {
                        // Skip if event class doesn't exist
                        if (! class_exists($eventClass)) {
                            continue;
                        }

                        if (! isset($mappings[$eventClass])) {
                            $mappings[$eventClass] = [];
                        }

                        if (! in_array($listenerClass, $mappings[$eventClass])) {
                            $mappings[$eventClass][] = $listenerClass;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Skip this path if there's an error (e.g., invalid directory)
                continue;
            }
        }

        return $mappings;
    }

    /**
     * Discover and register listeners, then register them with Laravel's event system.
     *
     * @param  string|array  $paths  Paths to scan for actions
     */
    public static function discoverAndRegister(string|array|null $paths = null): void
    {
        $mappings = static::discover($paths);

        foreach ($mappings as $eventClass => $listeners) {
            foreach ($listeners as $listenerClass) {
                Event::listen($eventClass, $listenerClass);
            }
        }
    }

    /**
     * Get event classes that a listener handles.
     *
     * @param  class-string  $listenerClass
     * @return array<class-string>
     */
    protected static function getEventClasses(string $listenerClass): array
    {
        $reflection = new \ReflectionClass($listenerClass);
        $eventClasses = [];

        // Check asListener method first
        if ($reflection->hasMethod('asListener')) {
            $method = $reflection->getMethod('asListener');
            $eventClasses = array_merge($eventClasses, static::getEventClassesFromMethod($method));
        }

        // Check handle method
        if ($reflection->hasMethod('handle')) {
            $method = $reflection->getMethod('handle');
            $eventClasses = array_merge($eventClasses, static::getEventClassesFromMethod($method));
        }

        // Check __invoke method
        if ($reflection->hasMethod('__invoke')) {
            $method = $reflection->getMethod('__invoke');
            $eventClasses = array_merge($eventClasses, static::getEventClassesFromMethod($method));
        }

        return array_unique($eventClasses);
    }

    /**
     * Extract event classes from a method's parameters.
     *
     * @return array<class-string>
     */
    protected static function getEventClassesFromMethod(\ReflectionMethod $method): array
    {
        $eventClasses = [];
        $parameters = $method->getParameters();

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (! $type) {
                continue;
            }

            // Handle union types and intersection types
            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                foreach ($type->getTypes() as $subType) {
                    if ($subType instanceof \ReflectionNamedType && ! $subType->isBuiltin()) {
                        $eventClasses[] = $subType->getName();
                    }
                }
            } elseif ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $eventClasses[] = $type->getName();
            }
        }

        return $eventClasses;
    }
}
