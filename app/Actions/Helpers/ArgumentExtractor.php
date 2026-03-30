<?php

declare(strict_types=1);

namespace App\Actions\Helpers;

/**
 * Helper class for extracting and typing arguments from variadic arrays.
 *
 * Provides a clean, readable way to extract arguments from variadic parameter
 * arrays with type validation and safety checks.
 *
 * Usage:
 *
 * ```php
 * // Extract two arguments: Team and array
 * [$team, $formData] = ArgumentExtractor::extract($arguments, Team::class, 'array');
 *
 * // Extract one argument: Team
 * [$team] = ArgumentExtractor::extract($arguments, Team::class);
 *
 * // Extract three arguments with types
 * [$user, $order, $items] = ArgumentExtractor::extract($arguments, User::class, Order::class, 'array');
 * ```
 */
class ArgumentExtractor
{
    /**
     * Extract and type arguments from variadic array.
     *
     * Extracts arguments by index with optional type validation.
     * Returns an array of extracted arguments that can be destructured.
     *
     * @param  array  $arguments  The variadic arguments array
     * @param  string|null  ...$types  Optional type hints for each argument (class name or 'array', 'string', etc.)
     * @return array Extracted arguments
     *
     * @example
     * // Extract two arguments: Team and array
     * [$team, $formData] = ArgumentExtractor::extract($arguments, Team::class, 'array');
     * @example
     * // Extract one argument: Team
     * [$team] = ArgumentExtractor::extract($arguments, Team::class);
     * @example
     * // Extract three arguments with types
     * [$user, $order, $items] = ArgumentExtractor::extract($arguments, User::class, Order::class, 'array');
     */
    public static function extract(array $arguments, ?string ...$types): array
    {
        $extracted = [];

        foreach ($types as $index => $type) {
            $value = $arguments[$index] ?? null;

            // Type checking/casting if needed
            if ($type && $value !== null) {
                // For class types, ensure it's an instance (or null)
                if (class_exists($type) || interface_exists($type)) {
                    $value = $value instanceof $type ? $value : null;
                }
                // For 'array', ensure it's an array
                elseif ($type === 'array') {
                    $value = is_array($value) ? $value : [];
                }
            }

            $extracted[] = $value;
        }

        return $extracted;
    }
}
