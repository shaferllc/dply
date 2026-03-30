<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\VersionDefault;
use App\Actions\Attributes\VersionHeader;
use App\Actions\Decorators\VersionDecorator;
use App\Actions\DesignPatterns\VersionDesignPattern;

/**
 * Handles multiple versions of the same action.
 *
 * Uses the decorator pattern to automatically wrap actions and handle versioning.
 * The VersionDecorator intercepts handle() calls and sets the version before execution.
 *
 * How it works:
 * 1. When an action uses AsVersioned, VersionDesignPattern recognizes it
 * 2. ActionManager wraps the action with VersionDecorator
 * 3. When handle() is called, the decorator:
 *    - Determines the version (from request header, method, or property)
 *    - Sets the version on the action
 *    - Executes the action's handle() method
 *    - Appends version to result as _version property
 *    - Returns the result
 *
 * @example
 * // ============================================
 * // Example 1: Minimal Setup (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\VersionDefault;
 * use App\Actions\Attributes\VersionHeader;
 *
 * #[VersionDefault('v2')]
 * #[VersionHeader('API-Version')]
 * class ProcessData extends Actions
 * {
 *     use AsVersioned;
 *
 *     public function handle(array $data): array
 *     {
 *         $version = $this->getVersion();
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
 *         // V1 logic
 *         return ['processed' => 'v1'];
 *     }
 *
 *     protected function processV2(array $data): array
 *     {
 *         // V2 logic
 *         return ['processed' => 'v2'];
 *     }
 * }
 *
 * // Usage - version determined from request header:
 * // Request with header: API-Version: v1
 * $result = ProcessData::run($data);
 * // $result = ['processed' => 'v1', '_version' => 'v1']
 * @example
 * // ============================================
 * // Example 2: Using Methods Instead of Attributes
 * // ============================================
 * class ProcessData extends Actions
 * {
 *     use AsVersioned;
 *
 *     public function handle(array $data): array
 *     {
 *         $version = $this->getVersion();
 *
 *         return match ($version) {
 *             'v1' => $this->processV1($data),
 *             'v2' => $this->processV2($data),
 *             default => $this->processV2($data),
 *         };
 *     }
 *
 *     // Customize how version is determined
 *     public function getVersion(): string
 *     {
 *         // Use custom header name
 *         return request()->header('X-API-Version', 'v2');
 *
 *         // Or use config
 *         // return config('app.api_version', 'v1');
 *
 *         // Or use different logic entirely
 *         // return auth()->user()?->preferred_version ?? 'v2';
 *     }
 *
 *     protected function processV1(array $data): array
 *     {
 *         return ['processed' => 'v1'];
 *     }
 *
 *     protected function processV2(array $data): array
 *     {
 *         return ['processed' => 'v2'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Explicitly Setting Version
 * // ============================================
 * #[VersionDefault('v2')]
 * #[VersionHeader('API-Version')]
 * class ProcessData extends Actions
 * {
 *     use AsVersioned;
 *
 *     public function handle(array $data): array
 *     {
 *         $version = $this->getVersion();
 *         return match ($version) {
 *             'v1' => $this->processV1($data),
 *             'v2' => $this->processV2($data),
 *             default => $this->processV2($data),
 *         };
 *     }
 * }
 *
 * // Set version explicitly (overrides header/default):
 * $result = ProcessData::version('v1')->handle($data);
 * // $result = ['processed' => 'v1', '_version' => 'v1']
 *
 * $result = ProcessData::version('v2')->handle($data);
 * // $result = ['processed' => 'v2', '_version' => 'v2']
 * @example
 * // ============================================
 * // Example 4: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\VersionDefault;
 * use App\Actions\Attributes\VersionHeader;
 *
 * #[VersionDefault('v2')]
 * #[VersionHeader('API-Version')]
 * class CreateTag extends Actions
 * {
 *     use AsVersioned;
 *
 *     public function handle(Team $team, array $formData)
 *     {
 *         $version = $this->getVersion();
 *
 *         return match ($version) {
 *             'v1' => $this->handleV1($team, $formData),
 *             'v2' => $this->handleV2($team, $formData),
 *             default => $this->handleV2($team, $formData),
 *         };
 *     }
 *
 *     protected function handleV1(Team $team, array $formData): Tag
 *     {
 *         // V1: Simple tag creation with basic slug
 *         $tagName = $formData['name'] ?? '';
 *         $tagSlug = Str::slug($tagName);
 *         $tag = Tag::findOrCreate(['slug' => $tagSlug], ['name' => $tagName]);
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 *
 *     protected function handleV2(Team $team, array $formData): Tag
 *     {
 *         // V2: Enhanced tag creation with type support
 *         $tagName = $formData['name'] ?? '';
 *         $tagType = $formData['type'] ?? TagType::Default;
 *         $tagSlug = Str::slug($tagName);
 *         $tag = Tag::findOrCreate(
 *             ['slug' => $tagSlug],
 *             ['name' => $tagName, 'type' => $tagType]
 *         );
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 * }
 *
 * // Usage:
 * // Request with header: API-Version: v1
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 * // $tag is a Tag model with $tag->_version = 'v1'
 *
 * // Or explicitly set version:
 * $tag = CreateTag::version('v2')->handle($team, ['name' => 'New Tag', 'type' => 'category']);
 * // $tag is a Tag model with $tag->_version = 'v2'
 * @example
 * // ============================================
 * // Example 5: Version Priority Order
 * // ============================================
 * // Version is determined in this order (highest to lowest priority):
 * //
 * // 1. Explicitly set via version() method:
 * //    ProcessData::version('v1')->handle($data);
 * //
 * // 2. Custom getVersion() method in action:
 * //    public function getVersion(): string { return 'v2'; }
 * //
 * // 3. Request header (from #[VersionHeader] or default 'API-Version'):
 * //    Request header: API-Version: v1
 * //
 * // 4. Default from #[VersionDefault] attribute:
 * //    #[VersionDefault('v2')]
 * //
 * // 5. Fallback to 'v1' if nothing else is set
 * @example
 * // ============================================
 * // Example 6: Accessing Version in Action
 * // ============================================
 * class ProcessData extends Actions
 * {
 *     use AsVersioned;
 *
 *     public function handle(array $data): array
 *     {
 *         // Get current version
 *         $version = $this->getVersion();
 *
 *         // Use version in logic
 *         if ($version === 'v1') {
 *             return $this->legacyProcess($data);
 *         }
 *
 *         return $this->modernProcess($data);
 *     }
 *
 *     // Set version programmatically (if needed)
 *     public function someMethod(): void
 *     {
 *         $this->setVersion('v2');
 *         $version = $this->getVersion(); // Returns 'v2'
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Version in Result
 * // ============================================
 * // The version is automatically appended to the result:
 * //
 * // For objects:
 * // $result->_version = 'v2'
 * //
 * // For arrays:
 * // $result['_version'] = 'v2'
 * //
 * // Example:
 * $tag = CreateTag::run($team, ['name' => 'Tag']);
 * // Access version:
 * $version = $tag->_version; // 'v2' (or whatever version was used)
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // Default version header: 'API-Version'
 * // Default version: 'v1' (if no attributes or methods specified)
 * //
 * // Version determination priority:
 * // 1. Explicit version() call
 * // 2. Custom getVersion() method
 * // 3. Request header (API-Version or custom from #[VersionHeader])
 * // 4. #[VersionDefault] attribute value
 * // 5. Fallback to 'v1'
 * //
 * // Version is automatically added to result as _version property/key
 *
 * @see VersionDecorator
 * @see VersionDesignPattern
 * @see VersionDefault
 * @see VersionHeader
 */
trait AsVersioned
{
    protected ?string $version = null;

    public function getVersion(): string
    {
        if ($this->version === null) {
            // Default: get from request header or default to 'v1'
            $this->version = request()->header('API-Version', 'v1');
        }

        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public static function version(string $version): mixed
    {
        $instance = static::make();

        // If instance is a decorator, unwrap to get the actual action
        $action = $instance;
        while (str_starts_with(get_class($action), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($action);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $action = $property->getValue($action);
            } else {
                break;
            }
        }

        // Set version on the actual action
        if (method_exists($action, 'setVersion')) {
            $action->setVersion($version);
        }

        return $instance;
    }
}
