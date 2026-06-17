<?php

declare(strict_types=1);

/**
 * Repair docblocks corrupted by fix-models-phpstan.php relation/generic passes.
 */
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

    // Remove mid-docblock @return injections: "... {@see Foo}. *\n * @return ...\n */"
    $content = preg_replace(
        '/(\{@see\s+[^}]+\})\.\s*\*\s*\n\s*\*\s*@return\s+[^\n]+\s*\n\s*\*\//',
        '$1.',
        $content
    ) ?? $content;

    // Collapse duplicate consecutive @return docblocks before a method.
    $content = preg_replace(
        '/(\/\*\*\s*@return\s+[^*]+\*\/\s*\n\s*)+\/\*\*\s*@return\s+([^*]+)\*\/\s*\n(\s*public\s+function)/',
        '/** @return $2 */'."\n".'$3',
        $content
    ) ?? $content;

    // Remove duplicate /** @return ... */ immediately before another /** @return ... */
    $content = preg_replace(
        '/(\/\*\*\s*@return\s+[^*]+\*\/\s*\n\s*)+\/\*\*\s*@return\s+([^*]+)\*\/\s*\n(\s*public\s+function)/',
        '/** @return $2 */'."\n".'$3',
        $content
    ) ?? $content;

    // Fix orphaned broken docblock fragments: " * @return ...\n */" without opening /**
    $content = preg_replace(
        '/\n\s*\*\s*@return\s+[^\n]+\s*\n\s*\*\/\s*\n(\s*\/\*\*\s*@return)/',
        "\n$1",
        $content
    ) ?? $content;

    // Merge docblock + duplicate single-line @return before method
    $content = preg_replace_callback(
        '/(\/\*\*[\s\S]*?\*\/)\s*\n\s*\/\*\*\s*@return\s+([^*]+)\*\/\s*\n(\s*public\s+function\s+\w+\([^)]*\)\s*:\s*\w+)/',
        static function (array $m): string {
            $doc = $m[1];
            $return = trim($m[2]);
            if (str_contains($doc, '@return')) {
                return $doc."\n".$m[3];
            }

            $doc = preg_replace('/\s*\*\/\s*$/', " *\n * @return {$return}\n */", $doc) ?? $doc;

            return $doc."\n".$m[3];
        },
        $content
    ) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Fixed: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
