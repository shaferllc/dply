<?php

declare(strict_types=1);

/**
 * Safe PHPStan level-6 fixes for app/Models (no docblock corruption).
 *
 * Usage: php scripts/fix-models-phpstan-remaining.php [--dry-run]
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);
$modelsDir = dirname(__DIR__).'/app/Models';
$factoriesDir = dirname(__DIR__).'/database/factories';

$factoryNames = [];
foreach (glob($factoriesDir.'/*Factory.php') ?: [] as $factoryFile) {
    $factoryNames[basename($factoryFile, '.php')] = true;
}

$nullableCarbonFields = [
    'env_synced_at', 'last_deploy_at', 'nginx_installed_at', 'scheduled_deletion_at',
    'ssl_installed_at', 'suspended_at', 'expires_at', 'last_used_at', 'accepted_at',
    'revoked_at', 'confirmed_at', 'completed_at', 'failed_at', 'started_at',
    'finished_at', 'deleted_at', 'published_at', 'shipped_at', 'read_at',
    'delivered_at', 'processed_at', 'cancelled_at', 'beta_joined_at', 'trial_ends_at',
    'cron_maintenance_until', 'locked_at', 'verified_at', 'activated_at',
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
    $basename = basename($path);
    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;
    $content = fixHasFactoryAnnotations($content, $factoryNames);
    $content = fixRelationGenerics($content);
    $content = fixBelongsToManyPivotGenerics($content, $basename);
    $content = fixNullableCarbonProperties($content, $nullableCarbonFields);
    $content = fixSpecificFiles($content, $basename);

    if ($content !== $original) {
        $changed++;
        if (! $dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry-run] ' : '')."Updated: {$path}\n";
    }
}

echo "Done. {$changed} file(s) ".($dryRun ? 'would be ' : '')."updated.\n";

function fixHasFactoryAnnotations(string $content, array $factoryNames): string
{
    return preg_replace_callback(
        '/\/\*\*\s*@use\s+HasFactory<([^>]+)>\s*\*\/\s*\n(\s*use\s+HasFactory)/',
        static function (array $m) use ($factoryNames): string {
            $factory = trim($m[1]);
            $factoryShort = str_contains($factory, '\\')
                ? substr($factory, strrpos($factory, '\\') + 1)
                : $factory;

            if (! isset($factoryNames[$factoryShort])) {
                return $m[2];
            }

            $fqcn = str_contains($factory, '\\')
                ? $factory
                : '\\Database\\Factories\\'.$factoryShort;

            if (! str_starts_with($fqcn, '\\')) {
                $fqcn = '\\Database\\Factories\\'.$factoryShort;
            }

            return "/** @use HasFactory<{$fqcn}> */\n".$m[2];
        },
        $content
    ) ?? $content;
}

function fixRelationGenerics(string $content): string
{
    return preg_replace(
        '/@return\s+(HasMany|HasOne|BelongsTo|BelongsToMany|MorphMany|MorphOne)<([A-Za-z\\\\]+)>(?!\s*,)/',
        '@return $1<$2, $this>',
        $content
    ) ?? $content;
}

function fixBelongsToManyPivotGenerics(string $content, string $basename): string
{
    $map = [
        'CloudBucket.php' => ['CloudBucketSite', 'Site'],
        'CloudDatabase.php' => ['CloudDatabaseSite', 'Site'],
    ];

    if (! isset($map[$basename])) {
        return $content;
    }

    [$pivot, $related] = $map[$basename];

    return preg_replace(
        '/@return\s+BelongsToMany<'.$related.',\s*\$this>/',
        "@return BelongsToMany<{$related}, \$this, {$pivot}, pivot>",
        $content
    ) ?? $content;
}

function fixNullableCarbonProperties(string $content, array $fields): string
{
    foreach ($fields as $field) {
        $content = preg_replace(
            '/@property\s+\\\\Illuminate\\\\Support\\\\Carbon\s+\$'.preg_quote($field, '/').'\b/',
            '@property ?\\Illuminate\\Support\\Carbon $'.$field,
            $content
        ) ?? $content;
    }

    return $content;
}

function fixSpecificFiles(string $content, string $basename): string
{
    return match ($basename) {
        'Site.php' => str_replace(
            '@property ?string $workspace_id',
            "@property ?string \$workspace_id\n * @property ?string \$project_id\n * @property ?string \$active_deploy_pipeline_id",
            $content
        ),
        'User.php' => fixUserFile($content),
        'WorkspaceMember.php' => str_replace(
            "    public static function roles(): array\n    {",
            "    /** @return list<string> */\n    public static function roles(): array\n    {",
            $content
        ),
        default => $content,
    };
}

function fixUserFile(string $content): string
{
    $content = preg_replace(
        '/\/\*\*\s*\n\s*\*\s*Get the attributes that should be cast\.\s*\n\s*\*\s*\n\s*\*\s*@return array<string, string>\s*\n\s*\*\/\s*\n\s*public function hasTwoFactorEnabled/',
        'public function hasTwoFactorEnabled',
        $content
    ) ?? $content;

    return preg_replace(
        '/\/\*\*\s*\n\s*\*\s*Get the attributes that should be cast\.\s*\n\s*\*\s*\n\s*\*\s*@return array<string, string>\s*\n\s*\*\/\s*\n\s*\/\*\*\s*@return array<string, string>\s*\*\/\s*\n\s*protected function casts/',
        "/**\n     * @return array<string, string>\n     */\n    protected function casts",
        $content
    ) ?? $content;
}
