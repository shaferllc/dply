<?php

declare(strict_types=1);

/**
 * Pass 5: nullable ports/datetimes, redundant guards, scope types, return types.
 */
$root = dirname(__DIR__);
$modelsDir = $root.'/app/Models';

$nullableStringFields = [
    'Site.php' => ['app_port', 'octane_port', 'runtime_version', 'runtime', 'database_engine'],
];

$nullableCarbonFields = [
    'DeviceAuthorization.php' => ['expires_at', 'authorized_at', 'delivered_at'],
    'Incident.php' => ['resolved_at'],
    'InsightFinding.php' => ['acknowledged_at'],
    'ServerRemoteAccessEvent.php' => ['started_at', 'ended_at'],
    'ServerSshSession.php' => ['expires_at'],
    'ServerWildcardCertificate.php' => ['expires_at', 'last_installed_at'],
    'SiteAccessGatePassword.php' => ['expires_at'],
    'SiteBackend.php' => ['activated_at'],
    'SiteBasicAuthUser.php' => ['expires_at'],
    'SiteDeploymentEphemeralCredential.php' => ['expires_at'],
    'SiteUptimeIncident.php' => ['resolved_at'],
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $basename = basename($path);
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $original = $content;

    if (isset($nullableStringFields[$basename])) {
        foreach ($nullableStringFields[$basename] as $field) {
            $content = preg_replace(
                '/@property string \$'.preg_quote($field, '/').'\b/',
                '@property ?string $'.$field,
                $content
            ) ?? $content;
        }
    }

    if (isset($nullableCarbonFields[$basename])) {
        foreach ($nullableCarbonFields[$basename] as $field) {
            $content = preg_replace(
                '/@property \\\\Illuminate\\\\Support\\\\Carbon \$'.preg_quote($field, '/').'\b/',
                '@property ?\\Illuminate\\Support\\Carbon $'.$field,
                $content
            ) ?? $content;
        }
    }

    $content = preg_replace(
        '/is_array\(\$this->meta\)\s*\?\s*\(\$this->meta\[/',
        '($this->meta ?? [])[',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/is_array\(\$this->meta\)\s*\?\s*\$this->meta\s*:\s*\[\]/',
        '$this->meta ?? []',
        $content
    ) ?? $content;

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "Updated {$basename}\n";
    }
}
