<?php

declare(strict_types=1);

/**
 * Repair docblock corruption from fix-models-phpstan.php without touching class headers.
 *
 * Usage: php scripts/repair-models-docblocks.php [--dry-run]
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);
$modelsDir = dirname(__DIR__).'/app/Models';

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

$changed = 0;

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;
    $content = repairMergedRelationDocblocks($content);
    $content = repairScopeBuilderDocblocks($content);
    $content = normalizeRelationMethodFormatting($content);

    if ($content !== $original) {
        $changed++;
        if (! $dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry-run] ' : '')."Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) ".($dryRun ? 'would be ' : '')."updated.\n";

function repairMergedRelationDocblocks(string $content): string
{
    // Multiline docblock ending with stray * before a second /** @return ... */
    $content = preg_replace_callback(
        '/\/\*\*\s*\n((?:\s*\*(?!\/)[^\n]*\n)+?)\s*\*?\s*\n\s*\/\*\*\s*(@return\s+[^\*]+)\*\//',
        static function (array $m): string {
            $body = rtrim($m[1]);
            $body = preg_replace('/\s+\*$/', '', $body) ?? $body;
            $return = trim($m[2]);

            return "/**\n{$body}\n *\n * {$return}\n */";
        },
        $content
    ) ?? $content;

    // Single-line /** prose * followed by /** @return ... */
    return preg_replace_callback(
        '/\/\*\*\s+(.+?)\s+\*\s*\n\s*\/\*\*\s*(@return\s+[^\*]+)\*\//s',
        static function (array $m): string {
            $prose = rtrim(trim($m[1]), '*');
            $return = trim($m[2]);

            return "/**\n * {$prose}\n *\n * {$return}\n */";
        },
        $content
    ) ?? $content;
}

function repairScopeBuilderDocblocks(string $content): string
{
    // Drop malformed duplicate scope docblocks that follow a valid one.
    $content = preg_replace(
        '/(\/\*\*\s*\n\s*\*\s*@param\s+Builder<static>\s+\$query\s*\n\s*\*\s*@return\s+Builder<static>\s*\n\s*\*\/)\s*\n\s*\/\*\*(?:\s*\n\s*)+\*\s*@param\s+Builder<static>\s+\$query(?:\s*\n\s*)+\*\s*@return\s+Builder<static>(?:\s*\n\s*)+\*\//',
        '$1',
        $content
    ) ?? $content;

    // Compact standalone malformed scope docblocks.
    $content = preg_replace(
        '/\/\*\*(?:\s*\n\s*)+\*\s*@param\s+Builder<static>\s+\$query(?:\s*\n\s*)+\*\s*@return\s+Builder<static>(?:\s*\n\s*)+\*\//',
        "/**\n     * @param Builder<static> \$query\n     * @return Builder<static>\n     */",
        $content
    ) ?? $content;

    // Remove empty docblocks immediately before another docblock.
    $content = preg_replace(
        '/\/\*\*\s*\*\/\s*\n\s*(?=\/\*\*)/',
        '',
        $content
    ) ?? $content;

    // Add scope docblock only when the method has none.
    return preg_replace_callback(
        '/(?<![\w\$])(?<indent>\s*)(?<doc>\/\*\*[\s\S]*?\*\/\s*)?public\s+function\s+(?<method>scope\w+)\(\s*Builder\s+\$(?<var>\w+)(?<rest>[^)]*)\)\s*:\s*Builder\s*\{/',
        static function (array $m): string {
            if (($m['doc'] ?? '') !== '' && str_contains($m['doc'], 'Builder<static>')) {
                return $m[0];
            }

            $indent = $m['indent'];
            $method = $m['method'];
            $var = $m['var'];
            $rest = $m['rest'];

            return $indent."/**\n"
                .$indent." * @param Builder<static> \${$var}\n"
                .$indent." * @return Builder<static>\n"
                .$indent." */\n"
                .$indent."public function {$method}(Builder \${$var}{$rest}): Builder {";
        },
        $content
    ) ?? $content;
}

function normalizeRelationMethodFormatting(string $content): string
{
    return preg_replace(
        '/(\/\*\*\s*@return\s+[^\*]+\*\/)\s*\n(\s*)public\s+function\s+(\w+)\(\):\s*(BelongsTo|HasMany|HasOne|BelongsToMany|MorphMany|MorphOne|MorphTo|HasManyThrough|HasOneThrough|MorphToMany)\s*\{/',
        "$1\n$2public function $3(): $4\n$2{",
        $content
    ) ?? $content;
}
