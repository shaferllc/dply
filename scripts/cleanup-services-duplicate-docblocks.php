<?php

declare(strict_types=1);

/**
 * Remove duplicate one-line @return docblocks and orphaned trailing fragments.
 */
$root = dirname(__DIR__).'/app/Services';
$changed = 0;

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

    // Remove duplicate one-liner @return when immediately after a closing */
    $content = preg_replace(
        '/(\*\/\s*\n)(\s*\/\*\* @return array<string, mixed> \*\/\s*\n)/',
        '$1',
        $content
    ) ?? $content;

    // Remove orphaned docblock lines after final class closing brace
    $content = preg_replace(
        '/\}\n(?:\s+\* @[^\n]+\n)+\s*$/',
        "}\n",
        $content
    ) ?? $content;

    if ($content !== $original) {
        $changed++;
        file_put_contents($path, $content);
        echo "Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
