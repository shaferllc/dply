<?php

declare(strict_types=1);

/**
 * Bulk-fix PHPStan level 6 issues in app/Models.
 *
 * Usage: php scripts/fix-models-phpstan.php [--dry-run]
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);
$modelsDir = dirname(__DIR__).'/app/Models';

$relationMethods = [
    'belongsTo' => 'BelongsTo',
    'hasMany' => 'HasMany',
    'hasOne' => 'HasOne',
    'belongsToMany' => 'BelongsToMany',
    'morphMany' => 'MorphMany',
    'morphOne' => 'MorphOne',
    'morphTo' => 'MorphTo',
    'hasManyThrough' => 'HasManyThrough',
    'hasOneThrough' => 'HasOneThrough',
    'morphToMany' => 'MorphToMany',
    'morphedByMany' => 'MorphToMany',
];

$castTypeMap = [
    'int' => 'int',
    'integer' => 'int',
    'float' => 'float',
    'double' => 'float',
    'string' => 'string',
    'bool' => 'bool',
    'boolean' => 'bool',
    'array' => 'array<string, mixed>',
    'json' => 'array<string, mixed>',
    'object' => 'object',
    'collection' => '\\Illuminate\\Support\\Collection<int, mixed>',
    'datetime' => '\\Illuminate\\Support\\Carbon',
    'date' => '\\Illuminate\\Support\\Carbon',
    'immutable_datetime' => '\\Illuminate\\Support\\CarbonImmutable',
    'immutable_date' => '\\Illuminate\\Support\\CarbonImmutable',
    'timestamp' => '\\Illuminate\\Support\\Carbon',
    'encrypted' => 'string',
    'encrypted:array' => 'array<string, mixed>',
    'encrypted:collection' => '\\Illuminate\\Support\\Collection<int, mixed>',
    'encrypted:object' => 'object',
    'hashed' => 'string',
];

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
    $content = fixArrayPropertyDocblocks($content);
    $content = fixCastsReturnType($content);
    $content = fixHasFactoryAnnotation($path, $content);
    $content = addModelPropertyDocblocks($content, $castTypeMap);
    $content = addRelationPropertyReads($content);
    $content = fixRelationGenerics($content, $relationMethods);
    $content = fixMorphToGenerics($content);
    $content = fixScopeBuilderGenerics($content);
    $content = fixSelfReferentialGenerics($content);

    if ($content !== $original) {
        $changed++;
        if (! $dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry-run] ' : '')."Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) ".($dryRun ? 'would be ' : '')."updated.\n";

function fixArrayPropertyDocblocks(string $content): string
{
    $patterns = [
        '/@property\s+array\s+\$(\w+)/' => '@property array<string, mixed> $$1',
        '/@property\s+\?array\s+\$(\w+)/' => '@property ?array<string, mixed> $$1',
        '/@property-read\s+array\s+\$(\w+)/' => '@property-read array<string, mixed> $$1',
        '/@property-read\s+\?array\s+\$(\w+)/' => '@property-read ?array<string, mixed> $$1',
    ];

    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content) ?? $content;
    }

    return $content;
}

function fixCastsReturnType(string $content): string
{
    if (preg_match('/@return\s+array<string,\s*string>\s*\*\/\s*\n\s*protected\s+function\s+casts/', $content)) {
        return $content;
    }

    return preg_replace(
        '/protected\s+function\s+casts\s*\(\)\s*:\s*array/',
        "/** @return array<string, string> */\n    protected function casts(): array",
        $content,
        1
    ) ?? $content;
}

function fixHasFactoryAnnotation(string $path, string $content): string
{
    if (! str_contains($content, 'HasFactory') || str_contains($path, '/Concerns/')) {
        return $content;
    }

    $factoryClass = null;
    if (preg_match('/protected\s+static\s+function\s+newFactory\s*\(\)\s*:\s*(\w+)/', $content, $m)) {
        $factoryClass = $m[1];
    } elseif (preg_match('/use\s+Database\\\\Factories\\\\(\w+Factory);/', $content, $m)) {
        $factoryClass = $m[1];
    } else {
        $factoryClass = basename($path, '.php').'Factory';
    }

    $factoryPath = dirname(__DIR__).'/database/factories/'.$factoryClass.'.php';
    if (! is_file($factoryPath)) {
        return $content;
    }

    if (preg_match('/@use\s+HasFactory<[^>]+>/', $content)) {
        return preg_replace(
            '/@use\s+HasFactory<[^>]+>/',
            "@use HasFactory<\\Database\\Factories\\{$factoryClass}>",
            $content,
            1
        ) ?? $content;
    }

    return preg_replace(
        '/^(\s+)(use\s+.*HasFactory.*;)/m',
        "$1/** @use HasFactory<\\Database\\Factories\\{$factoryClass}> */\n$1$2",
        $content,
        1
    ) ?? $content;
}

function parseFillable(string $content): array
{
    $block = null;

    if (preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $content, $m)) {
        $block = $m[1];
    } elseif (preg_match('/#\[Fillable\(\[(.*?)\]\)\]/s', $content, $m)) {
        $block = $m[1];
    }

    if ($block === null) {
        return [];
    }

    $block = preg_replace('/\/\/[^\n]*/', '', $block) ?? $block;
    $block = preg_replace('/\/\*.*?\*\//s', '', $block) ?? $block;
    preg_match_all("/'([^']+)'/", $block, $fields);

    return array_values(array_filter($fields[1], static fn (string $field): bool => preg_match('/^[a-z][a-z0-9_]*$/', $field) === 1));
}

function parseCasts(string $content): array
{
    if (! preg_match('/protected\s+function\s+casts\s*\(\)\s*:\s*array\s*\{.*?return\s*\[(.*?)\];/s', $content, $m)) {
        return [];
    }

    $casts = [];
    preg_match_all("/'([^']+)'\s*=>\s*([^,\n]+)/", $m[1], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $casts[$match[1]] = trim($match[2], " \t\n\r\0\x0B,");
    }

    return $casts;
}

function phpTypeForCast(string $castExpr, array $castTypeMap): string
{
    $castExpr = trim($castExpr, "'\"");

    if (str_contains($castExpr, '::class')) {
        return str_replace('::class', '', $castExpr);
    }

    if (preg_match('/^decimal:\d+$/', $castExpr)) {
        return 'string';
    }

    return $castTypeMap[$castExpr] ?? 'mixed';
}

function fieldIsNullable(string $field, array $casts = []): bool
{
    if (isset($casts[$field])) {
        return false;
    }

    return str_ends_with($field, '_at')
        || str_ends_with($field, '_id')
        || in_array($field, ['meta', 'comment', 'error', 'error_message', 'description', 'note'], true);
}

function addModelPropertyDocblocks(string $content, array $castTypeMap): string
{
    if (! preg_match('/^(.*?)((?:\/\*\*[\s\S]*?\*\/\s*)?(?:#\[[^\]]+\]\s*\n)*)(class|trait)\s+/s', $content, $m)) {
        return $content;
    }

    $before = $m[1];
    $docblock = $m[2];
    $classKeyword = $m[3];
    $rest = substr($content, strlen($before.$docblock.$classKeyword));

    $fillable = parseFillable($content);
    $casts = parseCasts($content);
    $fields = array_unique(array_merge($fillable, array_keys($casts)));

    $propertyLines = [];

    if (str_contains($content, 'HasUlids') && ! preg_match('/@property\s+string\s+\$id\b/', $docblock)) {
        $propertyLines['$id'] = '@property string $id';
    }

    foreach ($fields as $field) {
        $prop = '$'.$field;
        if (preg_match('/@property(?:-read)?\s+[^\n]*\s+\\'.preg_quote($prop, '/').'\b/', $docblock)) {
            continue;
        }

        $type = isset($casts[$field]) ? phpTypeForCast($casts[$field], $castTypeMap) : 'string';
        $nullable = fieldIsNullable($field, $casts);
        $docType = $nullable ? "?{$type}" : $type;
        $propertyLines[$prop] = "@property {$docType} \${$field}";
    }

    if ($propertyLines === []) {
        return $content;
    }

    ksort($propertyLines);

    if (preg_match('/\/\*\*(.*?)\*\//s', $docblock, $docMatch)) {
        $inner = $docMatch[1];
        $readPos = strpos($inner, '@property-read');
        foreach ($propertyLines as $line) {
            if (preg_match('/@property(?:-read)?\s+[^\n]*\s+\\'.preg_quote(substr($line, strrpos($line, '$')), '/').'\b/', $inner)) {
                continue;
            }
            if ($readPos !== false) {
                $inner = substr($inner, 0, $readPos)."\n * {$line}\n".substr($inner, $readPos);
                $readPos = strpos($inner, '@property-read', $readPos + strlen($line) + 4);
            } else {
                $inner = rtrim($inner)."\n * {$line}\n";
            }
        }
        $newDocblock = "/**{$inner} */\n";
    } else {
        $lines = implode("\n", array_map(static fn (string $line): string => " * {$line}", $propertyLines));
        $newDocblock = "/**\n{$lines}\n */\n";
    }

    return $before.$newDocblock.$classKeyword.$rest;
}

function addRelationPropertyReads(string $content): string
{
    if (! preg_match('/^(.*?)((?:\/\*\*[\s\S]*?\*\/\s*)?(?:#\[[^\]]+\]\s*\n)*)(class|trait)\s+/s', $content, $classMatch)) {
        return $content;
    }

    $before = $classMatch[1];
    $docblock = $classMatch[2];
    $classKeyword = $classMatch[3];
    $rest = substr($content, strlen($before.$docblock.$classKeyword));

    preg_match_all(
        '/\/\*\*\s*@return\s+(BelongsTo|HasOne|HasMany|BelongsToMany|MorphMany|MorphOne|HasManyThrough|HasOneThrough|MorphToMany)<([^,>]+)(?:,\s*\$this)?>\s*\*\/\s*\n\s*public\s+function\s+(\w+)\(\)/',
        $content,
        $matches,
        PREG_SET_ORDER
    );

    $propertyLines = [];
    foreach ($matches as $match) {
        $relationType = $match[1];
        $related = trim($match[2]);
        $name = $match[3];

        $propertyLines[$name] = match ($relationType) {
            'BelongsTo', 'HasOne' => "@property-read ?{$related} \${$name}",
            'HasMany', 'BelongsToMany', 'MorphMany', 'HasManyThrough', 'MorphToMany' => "@property-read \\Illuminate\\Database\\Eloquent\\Collection<int, {$related}> \${$name}",
            default => "@property-read ?{$related} \${$name}",
        };
    }

    if ($propertyLines === []) {
        return $content;
    }

    if (preg_match('/\/\*\*(.*?)\*\//s', $docblock, $docMatch)) {
        $inner = $docMatch[1];
        foreach ($propertyLines as $name => $line) {
            if (! preg_match('/@property(?:-read)?\s+[^\n]*\s+\$'.preg_quote($name, '/').'\b/', $inner)) {
                $inner = rtrim($inner)."\n * {$line}\n";
            }
        }
        $newDocblock = "/**{$inner} */\n";
    } else {
        $lines = implode("\n", array_map(static fn (string $line): string => " * {$line}", $propertyLines));
        $newDocblock = "/**\n{$lines}\n */\n";
    }

    return $before.$newDocblock.$classKeyword.$rest;
}

function fixRelationGenerics(string $content, array $relationMethods): string
{
    $relationTypes = implode('|', array_unique(array_values($relationMethods)));

    return preg_replace_callback(
        '/(?<![\w\$])(?<indent>\s*)(?<doc>\/\*\*(?:(?!\*\/).)*?\*\/\s*)?public\s+function\s+(?<name>\w+)\s*\((?<params>[^)]*)\)\s*:\s*(?<type>'.$relationTypes.')\s*\{(?<body>[\s\S]*?)\n\k<indent>\}/',
        static function (array $m) use ($relationMethods): string {
            $indent = $m['indent'];
            $doc = $m['doc'] ?? '';
            $name = $m['name'];
            $params = $m['params'];
            $type = $m['type'];
            $body = $m['body'];

            if ($doc !== '' && preg_match('/@return\s+'.$type.'<[^>]+>/', $doc)) {
                return $m[0];
            }

            $relatedModel = extractRelatedModel($body, $relationMethods, $type);
            if ($relatedModel === null) {
                return $m[0];
            }

            $returnLine = "@return {$type}<{$relatedModel}, \$this>";

            if ($doc !== '') {
                if (preg_match('/@return\s+\S+/', $doc)) {
                    $newDoc = preg_replace('/@return\s+\S+/', $returnLine, $doc);
                } else {
                    $newDoc = preg_replace('/\s*\*\/\s*$/', " *\n * {$returnLine}\n */", $doc);
                }

                return $indent.rtrim((string) $newDoc)."\n".$indent."public function {$name}({$params}): {$type} {{$body}\n{$indent}}";
            }

            return $indent."/** @return {$type}<{$relatedModel}, \$this> */\n"
                .$indent."public function {$name}({$params}): {$type} {{$body}\n{$indent}}";
        },
        $content
    ) ?? $content;
}

function fixMorphToGenerics(string $content): string
{
    return preg_replace_callback(
        '/(?<![\w\$])(?<indent>\s*)(?<doc>\/\*\*(?:(?!\*\/).)*?\*\/\s*)?public\s+function\s+(?<name>\w+)\s*\(\)\s*:\s*MorphTo\s*\{/',
        static function (array $m): string {
            $doc = $m['doc'] ?? '';
            if ($doc !== '' && preg_match('/@return\s+MorphTo<[^>]+>/', $doc)) {
                return $m[0];
            }

            return $m['indent'].'/** @return MorphTo<Model, $this> */'."\n".$m['indent'].'public function '.$m['name'].'(): MorphTo {';
        },
        $content
    ) ?? $content;
}

function fixScopeBuilderGenerics(string $content): string
{
    return preg_replace_callback(
        '/(?<indent>\s*)(?<doc>\/\*\*(?:(?!\*\/).)*?\*\/\s*)?public\s+function\s+(?<method>scope\w+)\(\s*Builder\s+\$(?<var>\w+)(?<rest>[^)]*)\)\s*:\s*Builder\s*\{/',
        static function (array $m): string {
            $doc = $m['doc'] ?? '';
            if ($doc !== '' && str_contains($doc, 'Builder<')) {
                return $m[0];
            }

            $indent = $m['indent'];
            $method = $m['method'];
            $var = $m['var'];
            $rest = $m['rest'];

            $newDoc = "/**\n * @param Builder<static> \${$var}\n * @return Builder<static>\n */\n";

            return $indent.$newDoc.$indent."public function {$method}(Builder \${$var}{$rest}): Builder {";
        },
        $content
    ) ?? $content;
}

function fixSelfReferentialGenerics(string $content): string
{
    return preg_replace(
        '/@return\s+(HasMany|HasOne|BelongsTo)<(\w+),\s*\2>/',
        '@return $1<$2, $this>',
        $content
    ) ?? $content;
}

function extractRelatedModel(string $body, array $relationMethods, string $returnType): ?string
{
    foreach ($relationMethods as $method => $type) {
        if ($type !== $returnType) {
            continue;
        }

        if (preg_match('/->'.$method.'\s*\(\s*([\\\\\w]+)::class/', $body, $m)) {
            return $m[1];
        }
    }

    return null;
}
