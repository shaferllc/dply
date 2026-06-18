<?php

declare(strict_types=1);

/**
 * Pass 6: bulk redundant guard removal and common type fixes.
 */
$root = dirname(__DIR__);
$modelsDir = $root.'/app/Models';

$replacements = [
    '/is_array\(\$this->meta\)\s*\?\s*\$this->meta\s*:\s*\[\]/' => '$this->meta ?? []',
    '/is_array\(\$this->metadata\)\s*\?\s*\$this->metadata\s*:\s*\[\]/' => '$this->metadata ?? []',
    '/is_array\(\$this->meta\s*\?\?\s*\[\]\)\s*\?\s*\(\$this->meta\s*\?\?\s*\[\]\)\[/' => '($this->meta ?? [])[',
    '/\$meta\s*=\s*is_array\(\$this->meta\)\s*\?\s*\$this->meta\s*:\s*\[\];/' => '$meta = $this->meta ?? [];',
    '/is_array\(\$this->meta\)\s*&&\s*\$this->meta\s*!==\s*null/' => 'true',
    '/is_array\(\$meta\)\s*\?\s*\$meta\s*:\s*\[\]/' => '$meta ?? []',
    '/is_string\(\$this->ssh_private_key\)\s*&&\s*/' => '',
    '/is_string\(\$this->ssh_public_key\)\s*&&\s*/' => '',
    '/is_string\(\$this->ssh_host_key\)\s*&&\s*/' => '',
    '/is_string\(\$this->ssh_user\)\s*&&\s*/' => '',
    '/is_string\(\$this->ssh_host\)\s*&&\s*/' => '',
    '/@property string \$container_backend\b/' => '@property ?string $container_backend',
    '/@property array \$meta\b/' => '@property array<string, mixed> $meta',
];

$scopePattern = '/(\s+)(public function scope(\w+)\([^)]*\))\s*\{/';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $original = $content;

    foreach ($replacements as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content) ?? $content;
    }

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo 'Updated '.str_replace($modelsDir.'/', '', $path)."\n";
    }
}

// Fix specific methods
$specific = [
    'Concerns/Site/TracksProvisioningStatus.php' => [
        'public function provisioningMeta(): array' => '/** @return array<string, mixed> */'."\n    ".'public function provisioningMeta(): array',
    ],
    'Concerns/Site/ManagesEdgeHosting.php' => [
        'public function mergeEdgeMeta(array $patch): void' => '/** @param array<string, mixed> $patch */'."\n    ".'public function mergeEdgeMeta(array $patch): void',
        'if ($this->container_backend === null)' => 'if ($this->container_backend === null || $this->container_backend === \'\')',
    ],
    'FunctionInvocation.php' => [
        'public function logLines(): array' => '/** @return list<string> */'."\n    ".'public function logLines(): array',
    ],
    'RealtimeApp.php' => [
        'public function generateCredentials(): array' => '/** @return array{app_id: string, app_key: string, app_secret: string} */'."\n    ".'public function generateCredentials(): array',
    ],
    'SiteDeployStep.php' => [
        'public static function typeLabels(): array' => '/** @return array<string, string> */'."\n    ".'public static function typeLabels(): array',
    ],
    'ServerPoolMember.php' => [
        '@property array $meta' => '@property array<string, mixed> $meta',
    ],
];

foreach ($specific as $rel => $subs) {
    $path = $modelsDir.'/'.$rel;
    if (! is_file($path)) {
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }
    $original = $content;
    foreach ($subs as $search => $replace) {
        if (! str_contains($content, $replace) && str_contains($content, $search)) {
            $content = str_replace($search, $replace, $content);
        }
    }
    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "Updated {$rel}\n";
    }
}
