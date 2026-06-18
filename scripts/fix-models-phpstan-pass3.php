<?php

declare(strict_types=1);

/**
 * Targeted PHPStan logic fixes in app/Models (pass 3).
 */
$root = dirname(__DIR__);
$modelsDir = $root.'/app/Models';

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modelsDir, FilesystemIterator::SKIP_DOTS)
);

$globalPatterns = [
    '/is_string\(\$this->(\w+)\)\s*&&\s*\$this->\1\s*!==\s*\'\'/' => '$this->\\1 !== \'\'',
    '/is_string\(\$this->(\w+)\)\s*&&\s*\$this->\1\s*!==\s*""/' => '$this->\\1 !== ""',
    '/! is_string\(\$this->(\w+)\)\s*\|\|\s*\$this->\1\s*===\s*\'\'/' => '$this->\\1 === \'\'',
    '/is_array\(\$this->meta\)\s*\?\s*\$this->meta\s*:\s*\[\]/' => '$this->meta ?? []',
    '/is_array\(\$this->metadata\)\s*\?\s*\$this->metadata\s*:\s*\[\]/' => '$this->metadata ?? []',
    '/is_array\(\$this->insights_preferences\)\s*\?\s*\$this->insights_preferences\s*:\s*\[\]/' => '$this->insights_preferences ?? []',
    '/is_array\(\$this->services_preferences\)\s*\?\s*\$this->services_preferences\s*:\s*\[\]/' => '$this->services_preferences ?? []',
    '/is_array\(\$this->connection\)\s*\?\s*\$this->connection\s*:\s*\[\]/' => '$this->connection ?? []',
    '/is_array\(\$this->meta\)\s*&&\s*\$this->meta\s*!==\s*null/' => '$this->meta !== null',
    '/\$this->type\?->value/' => '$this->type->value',
    '/\$this->provider\?->label\(\)/' => '$this->provider->label()',
];

$fileSpecific = [
    'Server.php' => [
        '/@property \\\\Illuminate\\\\Support\\\\Carbon \$comped_until/' => '@property ?\\Illuminate\\Support\\Carbon $comped_until',
        '/@property string \$pool_role/' => '@property ?string $pool_role',
        '/public function installedRuntimeKeys\(\): array/' => '/** @return list<string> */'."\n".'    public function installedRuntimeKeys(): array',
        '/\'port\' => \(int\) \(\$this->ssh_port \?: 22\),/' => '\'port\' => (string) ((int) ($this->ssh_port ?: 22)),',
    ],
    'Site.php' => [
        '/@property \\?string \$workspace_id/' => "@property ?string \$workspace_id\n * @property ?string \$project_id\n * @property ?string \$active_deploy_pipeline_id",
        '/\/\*\*\s*\n\s*\*\s*Site-level caching configuration[\s\S]*?\*\/\s*\n\s*public function resolveRouteBinding/' => 'public function resolveRouteBinding',
    ],
    'User.php' => [
        '/\/\*\*\s*\n\s*\*\s*Get the attributes that should be cast\.\s*\n\s*\*\s*\n\s*\*\s*@return array<string, string>\s*\n\s*\*\/\s*\n\s*public function hasTwoFactorEnabled\(\)/' => 'public function hasTwoFactorEnabled()',
        '/Schema::hasTable\\(\\(new static\\)->getTable\\(\\)\\)/' => 'Schema::hasTable((new self)->getTable())',
        '/\$organization->pivot !== null\\)\\s*\\{\\s*\n\s*\$organization->rememberMemberRoleFor\\(\\\$this, \\(string\\) \$organization->pivot->role\\);/' => '$organization->getRelation(\'pivot\') !== null) {'."\n".'            $organization->rememberMemberRoleFor($this, (string) data_get($organization->getRelation(\'pivot\'), \'role\'));',
    ],
    'WorkerPool.php' => [
        '/\/\*\* @return HasMany<Server> \*\//' => '/** @return HasMany<Server, $this> */',
        '/public function replicas\(\): HasMany/' => '/** @return HasMany<Server, $this> */'."\n".'    public function replicas(): HasMany',
    ],
    'Subscription.php' => [
        '/protected static function newFactory\(\): Factory/' => '/** @return Factory<Subscription> */'."\n".'    protected static function newFactory(): Factory',
    ],
    'ManagesOrganizationTrialState.php' => [
        '/\$subscription->ends_at !== null/' => 'data_get($subscription->getAttributes(), \'ends_at\') !== null',
        '/return \$subscription->ends_at;/' => 'return data_get($subscription->getAttributes(), \'ends_at\');',
        '/return \$this->subscription\(\'default\'\)\?->ends_at;/' => 'return data_get($this->subscription(\'default\')?->getAttributes() ?? [], \'ends_at\');',
    ],
    'ManagesOrganizationMembership.php' => [
        '/\$role = \$pivot \? \(string\) \$pivot->role : null;/' => '$role = $pivot !== null ? (string) data_get($pivot, \'role\') : null;',
    ],
    'ManagesOrganizationPreferences.php' => [
        '/return \\(bool\\) \\(\\\$this->mergedDatabaseWorkspaceSettings\\(\\)\\[\'credential_shares_enabled\'\\] \\?\\? true\\);/' => 'return (bool) $this->mergedDatabaseWorkspaceSettings()[\'credential_shares_enabled\'];',
        '/if \\(\\\$raw === null \\|\\| \\\$raw === \'\'\\)/' => 'if ($raw === null)',
    ],
    'ApiToken.php' => [
        '/@property array<string, mixed> \$abilities/' => '@property ?list<string> $abilities',
        '/@property array<string, mixed> \$allowed_ips/' => '@property ?list<string> $allowed_ips',
        '/if \\(! is_string\\(\\\$ab\\) \\|\\| \\\$ab === \'\'\\)/' => 'if ($ab === \'\')',
        '/\\\$prefix = explode\\(\'\\.\', \\\$ability, 2\\)\\[0\\] \\?\\? \'\';/' => '$parts = explode(\'.\', $ability, 2); $prefix = $parts[0];',
        '/public static function createToken\([\s\S]*?\\?array \\\$allowedIps = null\s*\): array/' => null, // handled separately
    ],
];

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $relative = str_replace($modelsDir.'/', '', $path);
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $original = $content;

    foreach ($globalPatterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content) ?? $content;
    }

    $basename = basename($path);
    if (isset($fileSpecific[$basename])) {
        foreach ($fileSpecific[$basename] as $pattern => $replacement) {
            if ($replacement === null) {
                continue;
            }
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }
    }

    // Concerns subpath matching
    foreach ($fileSpecific as $key => $patterns) {
        if (str_ends_with($relative, $key)) {
            foreach ($patterns as $pattern => $replacement) {
                if ($replacement === null) {
                    continue;
                }
                $content = preg_replace($pattern, $replacement, $content) ?? $content;
            }
        }
    }

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "Updated: {$relative}\n";
    }
}

// ApiToken createToken signature
$apiTokenPath = $modelsDir.'/ApiToken.php';
$apiToken = file_get_contents($apiTokenPath);
if ($apiToken !== false) {
    $apiToken = preg_replace(
        '/public static function createToken\(\s*User \$user,\s*Organization \$organization,\s*string \$name,\s*\?\\\\DateTimeInterface \$expiresAt = null,\s*\?array \$abilities = null,\s*\?array \$allowedIps = null\s*\): array/',
        '/** @return array{token: self, plaintext: string} */'."\n".'    public static function createToken('."\n".'        User $user,'."\n".'        Organization $organization,'."\n".'        string $name,'."\n".'        ?\\DateTimeInterface $expiresAt = null,'."\n".'        ?list<string> $abilities = null,'."\n".'        ?list<string> $allowedIps = null'."\n".'    ): array',
        $apiToken
    ) ?? $apiToken;
    $apiToken = preg_replace(
        '/if \(\$abilities === null \|\| \$abilities === \[\]\)/',
        'if ($abilities === null || $abilities === [])',
        $apiToken
    ) ?? $apiToken;
    file_put_contents($apiTokenPath, $apiToken);
    echo "Updated: ApiToken.php (createToken)\n";
}

echo "Done.\n";
