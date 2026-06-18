<?php

declare(strict_types=1);

/**
 * Clean duplicated / malformed @property docblocks in app/Models.
 *
 * Usage: php scripts/clean-model-docblocks.php [--dry-run]
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);
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
    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = cleanFile($original);
    $content = fixBrokenCastsDocblock($content);

    if ($content !== $original) {
        $changed++;
        if (! $dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry-run] ' : '')."Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) ".($dryRun ? 'would be ' : '')."updated.\n";

function cleanFile(string $content): string
{
    if (! preg_match('/^(.*?)((?:\/\*\*[\s\S]*?\*\/\s*)+(?:#\[[^\]]+\]\s*\n)*)(class|trait)\s+/s', $content, $m)) {
        return $content;
    }

    $before = $m[1];
    $docblocksAndAttrs = $m[2];
    $classKeyword = $m[3];
    $rest = substr($content, strlen($before.$docblocksAndAttrs.$classKeyword));

    if (! preg_match('/\/\*\*([\s\S]*?)\*\//', $docblocksAndAttrs, $docMatch, PREG_OFFSET_CAPTURE)) {
        return $content;
    }

    $docInner = $docMatch[1][0];
    $docStart = (int) $docMatch[0][1];
    $docLength = strlen($docMatch[0][0]);

    $cleanedInner = cleanDocblockInner($docInner);
    $newDocblock = '/**'.$cleanedInner.' */';
    $newDocblocksAndAttrs = substr($docblocksAndAttrs, 0, $docStart)
        .$newDocblock
        .substr($docblocksAndAttrs, $docStart + $docLength);

    return $before.$newDocblocksAndAttrs.$classKeyword.$rest;
}

function cleanDocblockInner(string $inner): string
{
    $lines = preg_split('/\r\n|\n|\r/', $inner) ?: [];
    $kept = [];
    $seenProperties = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        $trimmed = preg_replace('/^\*\s?/', '', $trimmed) ?? $trimmed;

        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^@property(-read)?\s+(.+?)\s+\$(\w+)\s*$/', $trimmed, $m)) {
            $kind = $m[1] !== '' ? '-read' : '';
            $name = '$'.$m[3];
            $key = ($kind !== '' ? 'read:' : 'prop:').$name;

            if (isset($seenProperties[$key])) {
                continue;
            }

            $seenProperties[$key] = true;
            $kept[] = ' * @property'.$kind.' '.$m[2].' $'.$m[3];

            continue;
        }

        $kept[] = ' * '.$trimmed;
    }

    if ($kept === []) {
        return $inner;
    }

    return "\n".implode("\n", $kept)."\n ";
}

function fixBrokenCastsDocblock(string $content): string
{
    return preg_replace(
        '/\/\*\*\s*@return\s+array<string,\s*string>\s*\*\/\s*\n\s*\*\/\s*\n\s*\/\*\*\s*@return\s+array<string,\s*string>\s*\*\/\s*\n\s*protected\s+function\s+casts\(\)/',
        "/** @return array<string, string> */\n    protected function casts()",
        $content
    ) ?? $content;
}
