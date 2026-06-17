<?php

declare(strict_types=1);

/**
 * Third-pass PHPStan fixes for app/Services driven by analyse JSON output.
 *
 * Usage: php scripts/fix-services-phpstan-pass3.php [--dry-run] [--json=/path]
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);
$jsonPath = '/tmp/services-phpstan.json';

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--json=')) {
        $jsonPath = substr($arg, 7);
    }
}

if (! is_file($jsonPath)) {
    fwrite(STDERR, "JSON not found: {$jsonPath}\n");
    exit(1);
}

$content = file_get_contents($jsonPath);
$start = strpos($content, '{');
$decoded = json_decode($start === false ? $content : substr($content, $start), true);
$byFile = [];

foreach ($decoded['files'] ?? [] as $file => $data) {
    foreach ($data['messages'] ?? [] as $message) {
        $byFile[$file][] = [
            'line' => (int) ($message['line'] ?? 0),
            'identifier' => (string) ($message['identifier'] ?? ''),
            'message' => (string) ($message['message'] ?? ''),
        ];
    }
}

$changedFiles = 0;

foreach ($byFile as $path => $fileErrors) {
    if (! is_file($path)) {
        continue;
    }

    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;
    $content = fixMissingIterableValue($content, $fileErrors);
    $content = fixNullCoalesceOffsets($content, $fileErrors);
    // fixReturnTypeMismatches / fixParameterByRefTypes / fixArgumentTypeListParams
    // disabled — they corrupt existing shape docblocks when @return spans multiple lines.
    $content = fixArrayValuesList($content, $fileErrors);

    if ($content !== $original) {
        $changedFiles++;
        if (! $dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry-run] ' : '')."Updated: {$path}\n";
    }
}

echo "Done. {$changedFiles} file(s) ".($dryRun ? 'would be ' : '')."updated.\n";

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixMissingIterableValue(string $content, array $fileErrors): string
{
    $fixes = [];

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'missingType.iterableValue') {
            continue;
        }

        $method = null;
        if (preg_match('/::(\w+)\(\)/', $error['message'], $mm)) {
            $method = $mm[1];
        }

        $type = 'array<string, mixed>';
        if (preg_match('/parameter \$(\w+) with no value type/', $error['message'], $m)) {
            $var = $m[1];
            if (preg_match('/list of/', $error['message']) || str_ends_with($var, 's') && ! str_contains($var, 'Ids')) {
                // heuristic: plural params often lists
            }
            if (preg_match('/expects list</', $error['message'])) {
                $type = 'list<mixed>';
            }
            $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [$var => $type], 'return' => null];
        } elseif (preg_match('/return type has no value type/', $error['message'])) {
            $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [], 'return' => 'array<string, mixed>'];
        }
    }

    return applyDocblockFixes($content, $fixes);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixNullCoalesceOffsets(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'nullCoalesce.offset') {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        if (! preg_match("/Offset '([^']+)'/", $error['message'], $m)) {
            continue;
        }

        $offset = $m[1];
        $line = $lines[$lineIdx];

        $newLine = preg_replace(
            '/(\[[\'"]'.preg_quote($offset, '/').'[\'"]\])\s*\?\?\s*(?:null|0|\'\'|""|\[\]|false|true|\$[a-zA-Z_][\w]*)\b/',
            '$1',
            $line
        );

        if ($newLine !== null && $newLine !== $line) {
            $lines[$lineIdx] = $newLine;
        }
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixReturnTypeMismatches(string $content, array $fileErrors): string
{
    $fixes = [];

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'return.type') {
            continue;
        }

        if (! preg_match('/::(\w+)\(\) should return (.+?) but returns (.+)\./', $error['message'], $m)) {
            continue;
        }

        $method = $m[1];
        $actual = trim($m[3]);

        $returnType = match (true) {
            str_starts_with($actual, 'list<') => $actual,
            str_starts_with($actual, 'array{') => $actual,
            $actual === 'null' => '?array<string, mixed>',
            default => $actual,
        };

        $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [], 'return' => $returnType];
    }

    return applyDocblockFixes($content, $fixes);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixArgumentTypeListParams(string $content, array $fileErrors): string
{
    $fixes = [];

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'argument.type') {
            continue;
        }

        if (! preg_match('/Parameter #\d+ \$(\w+) of (?:method|function)/', $error['message'], $pm)) {
            continue;
        }

        if (! preg_match('/expects (.+?), (.+?) given\./', $error['message'], $tm)) {
            continue;
        }

        $var = $pm[1];
        $expected = trim($tm[1]);

        if (! str_starts_with($expected, 'list<') && ! str_starts_with($expected, 'array{')) {
            continue;
        }

        $method = null;
        if (preg_match('/::(\w+)\(/', $error['message'], $mm)) {
            $method = $mm[1];
        }

        $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [$var => $expected], 'return' => null];
    }

    return applyDocblockFixes($content, $fixes);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixParameterByRefTypes(string $content, array $fileErrors): string
{
    $fixes = [];

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'parameterByRef.type') {
            continue;
        }

        if (! preg_match('/Parameter &\$(\w+) by-ref type of method .+::(\w+)\(\) expects (.+?), (.+?) given/', $error['message'], $m)) {
            continue;
        }

        $var = $m[1];
        $method = $m[2];
        $actual = trim($m[4]);

        $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [$var => $actual], 'return' => null];
    }

    return applyDocblockFixes($content, $fixes);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixArrayValuesList(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'arrayValues.list') {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        $line = $lines[$lineIdx];
        $newLine = preg_replace(
            '/array_values\((\$[a-zA-Z_][\w\->]*)\)/',
            'array_values(array_values($1))',
            $line,
            1
        );

        // Better: cast with array_is_list check or use array_values only when needed
        // PHPStan wants list - use array_values with proper annotation via variable assignment
        if ($newLine === $line && preg_match('/return (\$[a-zA-Z_][\w\->]*);/', $line, $rm)) {
            $var = $rm[1];
            $indent = preg_match('/^(\s*)/', $line, $im) ? $im[1] : '        ';
            $lines[$lineIdx] = $indent.'return array_values('.$var.');';
        } elseif ($newLine !== null && $newLine !== $line) {
            $lines[$lineIdx] = $newLine;
        }
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{method: ?string, line: int, params: array<string, string>, return: ?string}>  $fixes
 */
function applyDocblockFixes(string $content, array $fixes): string
{
    if ($fixes === []) {
        return $content;
    }

    $lines = explode("\n", $content);

    foreach ($fixes as $fix) {
        $fnLineIdx = -1;

        if ($fix['method'] !== null) {
            foreach ($lines as $idx => $line) {
                if (preg_match('/function\s+'.preg_quote($fix['method'], '/').'\s*\(/', $line)) {
                    $fnLineIdx = $idx;
                    break;
                }
            }
        }

        if ($fnLineIdx < 0) {
            $fnIdx = $fix['line'] - 1;
            $fnLineIdx = $fnIdx;
            while ($fnLineIdx >= 0 && ! preg_match('/function\s+\w+\s*\(/', $lines[$fnLineIdx])) {
                $fnLineIdx--;
            }
        }

        if ($fnLineIdx < 0) {
            continue;
        }

        $indent = preg_match('/^(\s*)/', $lines[$fnLineIdx], $m) ? $m[1] : '    ';

        $docEnd = $fnLineIdx;
        $docStart = $fnLineIdx;
        if ($fnLineIdx > 0 && preg_match('/^\s*\*\/\s*$/', $lines[$fnLineIdx - 1])) {
            $docEnd = $fnLineIdx - 1;
            $docStart = $docEnd;
            while ($docStart > 0 && ! preg_match('/^\s*\/\*\*/', $lines[$docStart])) {
                $docStart--;
            }
        } elseif ($fnLineIdx > 0 && preg_match('/^\s*\/\*\*/', $lines[$fnLineIdx - 1])) {
            $docStart = $fnLineIdx - 1;
            $docEnd = $docStart;
            while ($docEnd + 1 < count($lines) && ! preg_match('/^\s*\*\/\s*$/', $lines[$docEnd + 1])) {
                $docEnd++;
            }
        } else {
            $docStart = -1;
        }

        $newDocLines = [];

        if ($docStart >= 0) {
            $existing = implode("\n", array_slice($lines, $docStart, $docEnd - $docStart + 1));

            foreach ($fix['params'] as $var => $type) {
                if (preg_match('/@param\s+.*\$'.preg_quote($var, '/').'\b/', $existing)) {
                    $existing = preg_replace(
                        '/@param\s+[^$]*\$'.preg_quote($var, '/').'\b/',
                        '@param  '.$type.' $'.$var,
                        $existing
                    ) ?? $existing;
                } else {
                    $newDocLines[] = $indent.' * @param  '.$type.' $'.$var;
                }
            }

            if ($fix['return'] !== null) {
                if (preg_match('/@return\s+/', $existing)) {
                    $existing = preg_replace(
                        '/@return\s+[^\n*]+/',
                        '@return '.$fix['return'],
                        $existing,
                        1
                    ) ?? $existing;
                } else {
                    $newDocLines[] = $indent.' * @return '.$fix['return'];
                }
            }

            if ($newDocLines !== []) {
                array_splice($lines, $docEnd, 0, $newDocLines);
            } elseif ($existing !== implode("\n", array_slice($lines, $docStart, $docEnd - $docStart + 1))) {
                $existingLines = explode("\n", $existing);
                array_splice($lines, $docStart, $docEnd - $docStart + 1, $existingLines);
            }
        } else {
            $docblock = [$indent.'/**'];
            foreach ($fix['params'] as $var => $type) {
                $docblock[] = $indent.' * @param  '.$type.' $'.$var;
            }
            if ($fix['return'] !== null) {
                $docblock[] = $indent.' * @return '.$fix['return'];
            }
            $docblock[] = $indent.' */';
            array_splice($lines, $fnLineIdx, 0, $docblock);
        }
    }

    return implode("\n", $lines);
}
