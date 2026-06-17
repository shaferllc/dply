<?php

declare(strict_types=1);

/**
 * Normalize broken relation docblocks: merge duplicate @return lines and fix formatting.
 */
$modelsDir = dirname(__DIR__).'/app/Models';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

$changed = 0;

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;

    // Duplicate: broken block + duplicate single-line @return
    $content = preg_replace(
        '/\/\*\* ([^\n*]+) \*\n \* (@return[^\n]+)\n \*\/\n    \/\*\* (@return[^\n]+)\n/',
        "/** $1\n     * $2\n */\n",
        $content
    ) ?? $content;

    // Multiline description ending with " *" then @return
    $content = preg_replace(
        '/( \*[^\n]*)\.\s*\*\n \* (@return[^\n]+)\n \*\/\n    \/\*\* (@return[^\n]+)\n/',
        '$1.'."\n     * $2\n */\n",
        $content
    ) ?? $content;

    $content = preg_replace(
        '/( \*[^\n]*)\.\s*\*\n \* (@return[^\n]+)\n \*\/\n/',
        '$1.'."\n     * $2\n */\n",
        $content
    ) ?? $content;

    // Stray */ after @return array docblock before casts
    $content = preg_replace(
        '/(\/\*\* @return array<string, string> \*\/)\n \*\/\n(\s+protected function casts)/',
        '$1'."\n$2",
        $content
    ) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Fixed: {$path}\n";
    }
}

echo "Done. {$changed} file(s) fixed.\n";
