<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Models'));

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if (! preg_match('/extends Model/', $contents)) {
        continue;
    }

    $lines = [
        '@property \\Illuminate\\Support\\Carbon $created_at',
        '@property \\Illuminate\\Support\\Carbon $updated_at',
    ];

    if (! preg_match('/\/\*\*(.*?)\*\/\s*\n((?:final\s+)?class\s+)/s', $contents, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    $doc = $m[1][0];
    $classPos = $m[2][1];
    $changed = false;

    foreach ($lines as $line) {
        $prop = substr($line, strpos($line, '$'));
        if (str_contains($doc, $prop)) {
            continue;
        }
        $doc = rtrim($doc)."\n * {$line}\n";
        $changed = true;
    }

    if (! $changed) {
        continue;
    }

    $updated = substr($contents, 0, $m[0][1]).'/**'.$doc." */\n".substr($contents, $classPos);
    file_put_contents($file->getPathname(), $updated);
    echo 'UPDATED: '.$file->getPathname().PHP_EOL;
}
