<?php

namespace App\Services\Logging;

/**
 * Decides whether a site's committed `config/logging.php` has been customised
 * away from Laravel stock — the adoption guard from Q5/Q6. dply is about to
 * *own and overwrite* this file, so before enabling managed logging we warn the
 * operator (and tell them which channels look custom, the Q5(3) detail) so they
 * can re-add anything bespoke via the Custom (monolog) escape hatch.
 *
 * It works on the file's *text* (never executes it) by extracting the
 * channel→driver map and comparing to {@see CanonicalLoggingStock}. When the
 * Laravel major isn't catalogued, or the file can't be parsed, it degrades to a
 * soft "couldn't verify" warning rather than a false "clean".
 */
final class LoggingDivergenceDetector
{
    /**
     * @return array{
     *   customized: bool,
     *   verified: bool,
     *   non_stock_channels: list<string>,
     *   retyped_channels: list<string>,
     *   default_changed: bool,
     *   message: string,
     * }
     */
    /** @return array<string, mixed> */
    public function inspect(string $fileContents, int $laravelMajor): array
    {
        $stock = CanonicalLoggingStock::channels($laravelMajor);

        $parsed = $this->extractChannels($fileContents);
        if ($parsed === null) {
            return $this->result(false, false, [], [], false,
                __('Could not read this app’s config/logging.php — review it before enabling managed logging; dply will replace it.'));
        }

        if ($stock === null) {
            // Unknown major: we can still surface anything beyond the modern
            // stock names, but we can't be authoritative — warn softly.
            $modern = CanonicalLoggingStock::channels(13) ?? [];
            $novel = array_values(array_diff(array_keys($parsed), array_keys($modern)));

            return $this->result(true, false, $novel, [], false,
                __('Couldn’t verify against your Laravel version — review before enabling; dply will replace config/logging.php.'));
        }

        $nonStock = [];
        $retyped = [];
        foreach ($parsed as $name => $driver) {
            if (! array_key_exists($name, $stock)) {
                $nonStock[] = $name;

                continue;
            }
            if ($stock[$name] !== $driver) {
                $retyped[] = $name;
            }
        }

        $defaultChanged = $this->defaultChannel($fileContents) !== CanonicalLoggingStock::defaultChannel($laravelMajor);

        $customized = $nonStock !== [] || $retyped !== [] || $defaultChanged;

        return $this->result(
            $customized,
            true,
            $nonStock,
            $retyped,
            $defaultChanged,
            $customized
                ? __('Your config/logging.php has custom changes. Managed logging will replace this file — re-add custom channels via the Custom (monolog) type.')
                : __('Your config/logging.php matches Laravel stock — safe to take over.'),
        );
    }

    /**
     * Extract a channel→driver map from the file text. Returns null when no
     * channels block is found (can't parse → caller warns softly).
     *
     * Matches both the normal `'name' => ['driver' => '...']` shape and the
     * stock `emergency` channel (`'emergency' => ['path' => ...]`, no driver),
     * tolerating arbitrary whitespace/newlines between tokens.
     *
     * @return array<string, string>|null
     */
    private function extractChannels(string $contents): ?array
    {
        if (! str_contains($contents, "'channels'") && ! str_contains($contents, '"channels"')) {
            return null;
        }

        $map = [];

        // Channels declared with a driver.
        if (preg_match_all('/[\'"]([a-z0-9_]+)[\'"]\s*=>\s*\[\s*[\'"]driver[\'"]\s*=>\s*[\'"]([a-z]+)[\'"]/i', $contents, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $map[strtolower($hit[1])] = strtolower($hit[2]);
            }
        }

        // The driver-less emergency channel (path-only).
        if (preg_match('/[\'"]emergency[\'"]\s*=>\s*\[\s*[\'"]path[\'"]/i', $contents)) {
            $map['emergency'] = '_path_';
        }

        return $map === [] ? null : $map;
    }

    private function defaultChannel(string $contents): string
    {
        // env('LOG_CHANNEL', 'xxx') or a bare 'default' => 'xxx'
        if (preg_match('/[\'"]default[\'"]\s*=>\s*env\(\s*[\'"]LOG_CHANNEL[\'"]\s*,\s*[\'"]([a-z0-9_]+)[\'"]/i', $contents, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match('/[\'"]default[\'"]\s*=>\s*[\'"]([a-z0-9_]+)[\'"]/i', $contents, $m)) {
            return strtolower($m[1]);
        }

        return 'stack';
    }

    /**
     * @param  array<string, mixed> $nonStock
     * @param  array<string, mixed> $retyped
     * @return array{customized: bool, verified: bool, non_stock_channels: list<string>, retyped_channels: list<string>, default_changed: bool, message: string}
     */
    private function result(bool $customized, bool $verified, array $nonStock, array $retyped, bool $defaultChanged, string $message): array
    {
        return [
            'customized' => $customized,
            'verified' => $verified,
            'non_stock_channels' => array_values($nonStock),
            'retyped_channels' => array_values($retyped),
            'default_changed' => $defaultChanged,
            'message' => $message,
        ];
    }
}
