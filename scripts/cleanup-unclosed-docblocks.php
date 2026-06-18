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

    $content = preg_replace_callback(
        '/\/\*\*\s*\n((?:\s*\*[^\n]*\n)*?)\s*\/\*\*\s*@return\s+([^*]+)\*\/\s*\n(\s*public\s+function\s+\w+)/',
        static fn (array $m): string => '/** @return '.trim($m[2]).' */'."\n".$m[3],
        $original
    ) ?? $original;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Fixed: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
