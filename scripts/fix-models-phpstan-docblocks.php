<?php

declare(strict_types=1);

/**
 * Fix corrupted PHPDoc blocks where closing tags were lost before relation return lines.
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
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $original = $content;

    // Unclosed block immediately followed by /** @return ... */
    $content = preg_replace(
        '/(\/\*\*(?:(?!\*\/).)*?)(\n\s*\/\*\* @return [^*]+\*\/\s*\n\s*public function)/s',
        '$1 */$2',
        $content
    ) ?? $content;

    // Unclosed block where public function follows directly (no @return line)
    $content = preg_replace(
        '/(\/\*\*(?:(?!\*\/).)*?)(\n\s*public function \w+\(\)[^{]*\{)/s',
        '$1 */$2',
        $content
    ) ?? $content;

    // Duplicate /** @return lines - keep the second
    $content = preg_replace(
        '/(\/\*\*(?:(?!\*\/).)*?@return [^*]+\*\/\s*\n\s*)\/\*\* @return [^*]+\*\/\s*\n(\s*public function)/s',
        '$1$2',
        $content
    ) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo 'Updated: '.str_replace($modelsDir.'/', '', $path)."\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
