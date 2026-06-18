<?php

declare(strict_types=1);

/**
 * Fix stray */ lines and broken docblocks introduced by automated refactors.
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

    // Stray closing after single-line @return docblock
    $content = preg_replace(
        '/(\/\*\* @return[^\n]+\n) \*\/\n(\s+(?:public|protected|private) )/m',
        '$1$2',
        $content
    ) ?? $content;

    // @return line followed by lone */ before method
    $content = preg_replace(
        '/( \* @return[^\n]+\n) \*\/\n(\s+(?:public|protected|private) )/m',
        '$1 */'."\n$2",
        $content
    ) ?? $content;

    // Fix class docblocks: "   */" -> " */"
    $content = preg_replace('/^   \*\/$/m', ' */', $content) ?? $content;

    // Fix broken @property lines missing * prefix at start of class docblock
    $content = preg_replace('/\n@property /m', "\n * @property ", $content) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Fixed: {$path}\n";
    }
}

echo "Done. {$changed} file(s) fixed.\n";
