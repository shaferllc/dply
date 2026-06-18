<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Models'));

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if (! str_contains($contents, 'HasUlids') || str_contains($contents, '@property string $id')) {
        continue;
    }

    if (preg_match('/\/\*\*(.*?)\*\/\s*\n(class\s+)/s', $contents, $m, PREG_OFFSET_CAPTURE)) {
        $doc = $m[1][0];
        if (str_contains($doc, '@property string $id')) {
            continue;
        }
        $newDoc = "/**\n * @property string \$id\n".ltrim($doc, "\n");
        $classPos = $m[2][1];
        $updated = substr($contents, 0, $m[0][1]).$newDoc." */\n".substr($contents, $classPos);
    } else {
        $updated = preg_replace(
            '/(\n(?:final\s+)?class\s+)/',
            "\n/**\n * @property string \$id\n */\n$1",
            $contents,
            1
        );
    }

    if ($updated !== null && $updated !== $contents) {
        file_put_contents($file->getPathname(), $updated);
        echo 'UPDATED: '.$file->getPathname().PHP_EOL;
    }
}
