<?php

declare(strict_types=1);

/**
 * Second-pass PHPStan fixes for app/Models.
 *
 * - Remove HasFactory/@use when no database/factories/*Factory.php exists
 * - Fix BelongsToMany pivot generics from ->using(PivotModel::class)
 */
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
    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;
    $basename = basename($path, '.php');
    $factoryPath = $factoriesDir.'/'.$basename.'Factory.php';

    if (str_contains($content, 'HasFactory') && ! is_file($factoryPath)) {
        $content = preg_replace('/^\s*\/\*\*\s*@use\s+HasFactory<[^>]+>\s*\*\/\s*\n/m', '', $content) ?? $content;
        $content = preg_replace('/^\s*use\s+Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\HasFactory;\s*\n/m', '', $content) ?? $content;
        $content = preg_replace('/\bHasFactory,\s*/', '', $content) ?? $content;
        $content = preg_replace('/,\s*HasFactory\b/', '', $content) ?? $content;
        $content = preg_replace('/\buse\s+HasFactory;\s*\n/', '', $content) ?? $content;
    }

    $content = preg_replace_callback(
        '/(\/\*\*\s*@return\s+BelongsToMany<[^>]+>\s*\*\/\s*\n\s*public\s+function\s+\w+\(\)\s*:\s*BelongsToMany\s*\{[\s\S]*?->using\(([\\\\\w]+)::class\))/m',
        static function (array $m): string {
            $block = $m[0];
            $pivot = $m[1];

            if (preg_match('/@return\s+BelongsToMany<([^,>]+),\s*\$this(?:,\s*[^>]+)?>/', $block, $returnMatch)) {
                $related = trim($returnMatch[1]);
                $fixedReturn = "@return BelongsToMany<{$related}, \$this, {$pivot}, 'pivot'>";

                return preg_replace(
                    '/@return\s+BelongsToMany<[^>]+>/',
                    $fixedReturn,
                    $block,
                    1
                ) ?? $block;
            }

            return $block;
        },
        $content
    ) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
        echo "Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) updated.\n";
