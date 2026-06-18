<?php

declare(strict_types=1);

$modelsDir = dirname(__DIR__).'/app/Models';
$changed = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

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

    // Remove duplicate closing " */" immediately after a closed docblock.
    $content = preg_replace('/(\*\/)\s*\n\s*\*\/\s*\n/', "$1\n", $content) ?? $content;

    // Collapse duplicate consecutive @return docblocks before a method.
    $content = preg_replace_callback(
        '/(?:\/\*\*(?:\s*\*[^\n]*\n)*?\s*\* @return[^\n]+\*\/\s*\n)+(\s*(?:public|protected)\s+function\s+\w+)/',
        static function (array $m): string {
            preg_match_all('/@return\s+([^*\n]+)/', $m[0], $returns);
            $last = trim(end($returns[1]) ?: 'mixed');

            return '/** @return '.$last.' */'."\n".$m[1];
        },
        $content
    ) ?? $content;

    // Ensure casts() has a single docblock.
    $content = preg_replace(
        '/(?:\/\*\* @return array<string, string> \*\/\s*\n)+(\s*protected function casts\s*\(\)\s*:\s*array)/',
        "/** @return array<string, string> */\n$1",
        $content
    ) ?? $content;

    // Fix empty docblocks before methods.
    $content = preg_replace(
        '/\/\*\*\s*\n\s*\n\s*\n(\s*(?:public|protected)\s+function)/',
        '$1',
        $content
    ) ?? $content;

    // Normalize excessive blank lines (max 2).
    $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Fixed: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
