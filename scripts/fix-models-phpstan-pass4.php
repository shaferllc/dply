<?php

declare(strict_types=1);

/**
 * Pass 4: nullable datetime @property, HasFactory cleanup, meta is_array removal.
 */
$root = dirname(__DIR__);
$modelsDir = $root.'/app/Models';
$factoriesDir = $root.'/database/factories';

$nullableDateFields = [
    'comped_until', 'read_at', 'saved_at', 'expires_at', 'ends_at', 'revoked_at',
    'accepted_at', 'consumed_at', 'used_at', 'confirmed_at', 'resolved_at',
    'acknowledged_at', 'delivered_at', 'opened_at', 'closed_at', 'started_at',
    'finished_at', 'completed_at', 'failed_at', 'cancelled_at', 'paused_at',
    'resumed_at', 'last_used_at', 'last_stats_at', 'last_installed_at',
    'last_health_check_at', 'scheduled_deletion_at', 'suspended_at', 'env_synced_at',
    'nginx_installed_at', 'ssl_installed_at', 'last_deploy_at', 'trial_ends_at',
    'cron_maintenance_until', 'hard_pause_starts_at', 'two_factor_confirmed_at',
    'email_verified_at', 'referral_converted_at', 'queued_at', 'started_at',
    'ended_at', 'expires_at', 'revoked_at', 'activated_at', 'deactivated_at',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $basename = basename($path, '.php');
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $original = $content;
    $factoryPath = $factoriesDir.'/'.$basename.'Factory.php';

    if (str_contains($content, 'HasFactory') && ! is_file($factoryPath)) {
        $content = preg_replace('/^\s*\/\*\*\s*@use\s+HasFactory<[^>]+>\s*\*\/\s*\n/m', '', $content) ?? $content;
        $content = preg_replace('/^\s*use\s+Illuminate\\\\Database\\\\Eloquent\\\\Factories\\\\HasFactory;\s*\n/m', '', $content) ?? $content;
        $content = preg_replace('/\bHasFactory,\s*/', '', $content) ?? $content;
        $content = preg_replace('/,\s*HasFactory\b/', '', $content) ?? $content;
        $content = preg_replace('/\buse\s+HasFactory;\s*\n/', '', $content) ?? $content;
    }

    foreach ($nullableDateFields as $field) {
        $content = preg_replace(
            '/@property \\\\Illuminate\\\\Support\\\\Carbon \$(?:'.preg_quote($field, '/').')\b/',
            '@property ?\\Illuminate\\Support\\Carbon $'.$field,
            $content
        ) ?? $content;
        $content = preg_replace(
            '/@property \\\\Illuminate\\\\Support\\\\CarbonImmutable \$(?:'.preg_quote($field, '/').')\b/',
            '@property ?\\Illuminate\\Support\\CarbonImmutable $'.$field,
            $content
        ) ?? $content;
    }

    // Generic: *_at Carbon properties default nullable unless already ?
    $content = preg_replace(
        '/@property (?!\?)\\\\Illuminate\\\\Support\\\\Carbon \$(\w+_at)\b/',
        '@property ?\\Illuminate\\Support\\Carbon $$1',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/@property string \$(pool_role)\b/',
        '@property ?string $$1',
        $content
    ) ?? $content;

    $replacements = [
        '/is_array\(\$this->meta\)\s*\?\s*\$this->meta\s*:\s*\[\]/' => '$this->meta ?? []',
        '/is_array\(\$this->meta\)\s*\?\s*\$this->meta/' => '$this->meta',
        '/is_array\(\$this->metadata\)\s*\?\s*\$this->metadata\s*:\s*\[\]/' => '$this->metadata ?? []',
        '/is_string\(\$this->(\w+)\)\s*&&\s*\$this->\1\s*!==\s*\'\'/' => '$this->\\1 !== \'\'',
        '/! is_string\(\$this->(\w+)\)\s*\|\|\s*\$this->\1\s*===\s*\'\'/' => '$this->\\1 === \'\'',
        '/is_string\(\$this->(\w+)\)\s*&&\s*\$this->\1\s*!==\s*""/' => '$this->\\1 !== ""',
    ];

    foreach ($replacements as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content) ?? $content;
    }

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo str_replace($modelsDir.'/', '', $path)."\n";
    }
}

// Trait property docs for Site fields used in traits
$hasSiteRelationships = $modelsDir.'/Concerns/Site/HasSiteRelationships.php';
$content = file_get_contents($hasSiteRelationships);
if ($content !== false && ! str_contains($content, '$active_deploy_pipeline_id')) {
    $content = str_replace(
        ' * @property-read ?SiteDeployPipeline $activeDeployPipeline',
        " * @property ?string \$active_deploy_pipeline_id\n * @property-read ?SiteDeployPipeline \$activeDeployPipeline",
        $content
    );
    file_put_contents($hasSiteRelationships, $content);
    echo "HasSiteRelationships.php (active_deploy_pipeline_id)\n";
}

echo "Done.\n";
