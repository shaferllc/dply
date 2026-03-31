<?php

declare(strict_types=1);

namespace App\Actions\IDE;

use App\Actions\ActionRegistry;

/**
 * Action IDE Integration - IDE helpers and autocomplete.
 *
 * Provides IDE integration helpers for PhpStorm, Laravel Idea, and VS Code.
 *
 * @example
 * // Generate IDE metadata
 * ActionIDEHelper::generateMetadata();
 * @example
 * // Get action suggestions for autocomplete
 * $suggestions = ActionIDEHelper::getSuggestions('Process');
 * @example
 * // Generate PhpStorm metadata
 * ActionIDEHelper::generatePhpStormMetadata();
 */
class ActionIDEHelper
{
    /**
     * Generate IDE metadata file.
     *
     * @param  string  $format  Format (phpstorm, vscode, json)
     * @return string Generated metadata
     */
    public static function generateMetadata(string $format = 'phpstorm'): string
    {
        $actions = ActionRegistry::discover();
        $metadata = [];

        foreach ($actions as $actionClass) {
            $reflection = new \ReflectionClass($actionClass);
            $handleMethod = $reflection->getMethod('handle');
            $parameters = $handleMethod->getParameters();

            $metadata[] = [
                'class' => $actionClass,
                'name' => class_basename($actionClass),
                'namespace' => $reflection->getNamespaceName(),
                'parameters' => array_map(fn ($p) => [
                    'name' => $p->getName(),
                    'type' => $p->getType()?->getName(),
                    'optional' => $p->isOptional(),
                ], $parameters),
            ];
        }

        return match ($format) {
            'json' => json_encode($metadata, JSON_PRETTY_PRINT),
            'phpstorm' => static::generatePhpStormMetadata($metadata),
            'vscode' => static::generateVSCodeMetadata($metadata),
            default => json_encode($metadata, JSON_PRETTY_PRINT),
        };
    }

    /**
     * Get action suggestions for autocomplete.
     *
     * @param  string  $query  Search query
     * @return array<string> Action class names
     */
    public static function getSuggestions(string $query): array
    {
        $actions = ActionRegistry::search($query);

        return $actions->map(fn ($action) => class_basename($action))->toArray();
    }

    /**
     * Generate PhpStorm metadata.
     */
    protected static function generatePhpStormMetadata(array $metadata): string
    {
        $php = "<?php\n\n";
        $php .= "/**\n";
        $php .= " * Auto-generated Action metadata for PhpStorm\n";
        $php .= ' * Generated: '.now()->toDateTimeString()."\n";
        $php .= " */\n\n";

        foreach ($metadata as $action) {
            $php .= "/**\n";
            $php .= " * @method static {$action['name']}::run(...\$arguments)\n";
            $php .= " */\n";
            $php .= "class {$action['name']}Meta\n";
            $php .= "{\n";
            $php .= "}\n\n";
        }

        return $php;
    }

    /**
     * Generate VS Code metadata.
     */
    protected static function generateVSCodeMetadata(array $metadata): string
    {
        $json = [
            'version' => '1.0.0',
            'actions' => $metadata,
        ];

        return json_encode($json, JSON_PRETTY_PRINT);
    }
}
