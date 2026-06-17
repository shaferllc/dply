<?php

declare(strict_types=1);

/**
 * Second-pass: fix bare `array` in PHPDoc shapes and add missing @return for `: array` methods.
 */
$root = dirname(__DIR__);
$dirs = [$root.'/app/Services', $root.'/app/Support', $root.'/app/TaskRunner'];
$changed = 0;

foreach ($dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        $path = $fileInfo->getPathname();
        if (str_contains($path, '/config/')) {
            continue;
        }

        $original = file_get_contents($path);
        if ($original === false) {
            continue;
        }

        $content = $original;

        // Only touch PHPDoc blocks — never native PHP type hints.
        $content = preg_replace_callback(
            '/\/\*\*(?:[^*]|\*(?!\/))*\*\//s',
            static function (array $m): string {
                $doc = $m[0];
                $doc = preg_replace('/([?:]\s*)\?array(?![<{\w])/', '$1?array<string, mixed>', $doc) ?? $doc;
                $doc = preg_replace('/([?:,]\s*)array(?![<{\w])/', '$1array<string, mixed>', $doc) ?? $doc;
                $doc = preg_replace('/@param\s+\??array\s+(\$\w+)/', '@param  array<string, mixed> $1', $doc) ?? $doc;
                $doc = preg_replace('/@return\s+\??array\s*\*/', '@return array<string, mixed> */', $doc) ?? $doc;
                $doc = preg_replace('/@return\s+\??array\s*$/m', '@return array<string, mixed>', $doc) ?? $doc;

                return $doc;
            },
            $content
        ) ?? $content;

        // Add @return for public/protected methods with `: array` return lacking docblock
        $content = preg_replace_callback(
            '/(\n    (?:public|protected) function \w+\([^)]*\): array\n    \{)/',
            static function (array $m): string {
                if (str_contains($m[0], '@return')) {
                    return $m[0];
                }

                return "\n    /** @return array<string, mixed> */".$m[0];
            },
            $content
        ) ?? $content;

        if ($content !== $original) {
            $changed++;
            file_put_contents($path, $content);
            echo "Updated: {$path}\n";
        }
    }
}

echo "Done. {$changed} file(s) updated.\n";
