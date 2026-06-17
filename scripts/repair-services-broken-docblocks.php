<?php

declare(strict_types=1);

/**
 * Repair broken docblocks from pass3: unterminated @return lines and native array generics.
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

    // Fix unterminated one-line docblocks before function declarations
    $content = preg_replace_callback(
        '/(\n)(    \/\*\* @return ([^\n*]+))\n(    (?:public|protected|private) function )/m',
        static function (array $m): string {
            return $m[1].'    /**'."\n".'     * @return '.$m[3]."\n".'     */'."\n".$m[4];
        },
        $content
    ) ?? $content;

    // Native PHP signatures must not use array<string, mixed>
    $content = str_replace('): array<string, mixed>', '): array', $content);
    $content = preg_replace(
        '/(fn \([^)]*\)): array<string, mixed>/',
        '$1: array',
        $content
    ) ?? $content;

    if ($content !== $original) {
        $changed++;
        file_put_contents($path, $content);
        echo "Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
