<?php

declare(strict_types=1);

/**
 * Repair PHPDoc corruption from pass3 parameter/return type fixes.
 */
$root = dirname(__DIR__).'/app/Services';
$changed = 0;

$listStringParams = [
    'detectedFiles', 'reasons', 'warnings', 'log', 'applied', 'found', 'output',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if ($fileInfo->getExtension() !== 'php') {
        continue;
    }

    $path = $fileInfo->getPathname();
    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;

    foreach ($listStringParams as $param) {
        $content = str_replace(
            "@param  mixed>, array<int|string, mixed> \${$param}",
            "@param  list<string> \${$param}",
            $content
        );
    }

    // Doubled return types
    while (str_contains($content, 'array<string, mixed><string, mixed>')) {
        $content = str_replace('array<string, mixed><string, mixed>', 'array<string, mixed>', $content);
    }

    // Broken list<array<string $var patterns
    $content = preg_replace(
        '/@param\s+list<array<string\s+\$(\w+)/',
        '@param  list<array<string, mixed>> $1',
        $content
    ) ?? $content;

    // Broken array{ shapes missing comma/colon
    $content = preg_replace(
        '/@param\s+array\{([^}:]+)\s+\$(\w+)/',
        '@param  array{$1: mixed} $2',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/@param\s+list<array\{([^}:]+)\s+\$(\w+)/',
        '@param  list<array{$1: mixed}> $2',
        $content
    ) ?? $content;

    if ($content !== $original) {
        $changed++;
        file_put_contents($path, $content);
        echo "Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
