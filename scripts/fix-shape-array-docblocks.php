<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = [$root.'/app/Services', $root.'/app/Support', $root.'/app/Modules/TaskRunner'];
$changed = 0;

foreach ($dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getExtension() !== 'php' || str_contains($fileInfo->getPathname(), '/config/')) {
            continue;
        }
        $path = $fileInfo->getPathname();
        $original = file_get_contents($path);
        $content = preg_replace_callback(
            '/\/\*\*(?:[^*]|\*(?!\/))*\*\//s',
            static function (array $m): string {
                $doc = $m[0];
                if (! str_contains($doc, 'array{')) {
                    return $doc;
                }
                $doc = preg_replace('/([?:]\s*)\?array(?![<{\w])/', '$1?array<string, mixed>', $doc) ?? $doc;
                $doc = preg_replace('/([?:,]\s*)array(?![<{\w])/', '$1array<string, mixed>', $doc) ?? $doc;

                return $doc;
            },
            $original
        ) ?? $original;
        if ($content !== $original) {
            file_put_contents($path, $content);
            $changed++;
        }
    }
}

echo "Shape fix: {$changed} files\n";
