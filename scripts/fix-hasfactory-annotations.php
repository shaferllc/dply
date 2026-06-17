<?php

declare(strict_types=1);

$modelsDir = dirname(__DIR__).'/app/Models';
$factoriesDir = dirname(__DIR__).'/database/factories';

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
    if ($content === false || ! str_contains($content, 'HasFactory')) {
        continue;
    }

    $original = $content;
    $className = basename($path, '.php');
    $factoryFile = $factoriesDir.'/'.$className.'Factory.php';
    $hasFactory = is_file($factoryFile);

    if ($hasFactory) {
        $factoryClass = $className.'Factory';
        if (! str_contains($content, 'use Database\\Factories\\'.$factoryClass)) {
            $content = preg_replace(
                '/(namespace App\\Models;\s*\n)/',
                "$1use Database\\Factories\\{$factoryClass};\n",
                $content,
                1
            ) ?? $content;
        }
        $content = preg_replace(
            '/\/\*\* @use HasFactory<[^>]+> \*\/\s*\n/',
            "/** @use HasFactory<\\Database\\Factories\\{$factoryClass}> */\n",
            $content,
            1
        ) ?? $content;
    } else {
        $content = preg_replace('/\s*\/\*\* @use HasFactory<[^>]+> \*\/\s*\n/', "\n", $content) ?? $content;
        $content = preg_replace('/,\s*HasFactory/', '', $content) ?? $content;
        $content = preg_replace('/HasFactory,\s*/', '', $content) ?? $content;
        $content = preg_replace('/use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\s*\n/', '', $content) ?? $content;
    }

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo ($hasFactory ? 'Fixed factory import: ' : 'Removed HasFactory: ').$path."\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
