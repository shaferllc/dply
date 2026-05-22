<?php

namespace App\Services\Cloud;

use App\Models\Cloud\CloudApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Detects application runtime and framework from git repositories.
 *
 * Analyzes repository contents to determine the appropriate runtime
 * (PHP, Ruby, Node.js, Python) and framework (Laravel, Rails, etc.)
 * for deploying to dply Cloud.
 */
class RuntimeDetector
{
    /**
     * Detection result structure.
     */
    public function detect(string $repositoryUrl, ?string $branch = null): RuntimeProfile
    {
        $branch = $branch ?? 'main';

        Log::info('Detecting runtime for repository', [
            'repository' => $repositoryUrl,
            'branch' => $branch,
        ]);

        try {
            $files = $this->fetchRepositoryFiles($repositoryUrl, $branch);

            // Check for PHP/Laravel
            if ($this->isPhpRepository($files)) {
                $framework = $this->detectPhpFramework($files);
                $version = $this->detectPhpVersion($files);

                return new RuntimeProfile(
                    runtime: 'php',
                    runtimeVersion: $version,
                    framework: $framework,
                    confidence: $this->calculateConfidence($files, 'php', $framework),
                    detectedFiles: $files,
                );
            }

            // Check for Ruby/Rails
            if ($this->isRubyRepository($files)) {
                $framework = $this->detectRubyFramework($files);
                $version = $this->detectRubyVersion($files);

                return new RuntimeProfile(
                    runtime: 'ruby',
                    runtimeVersion: $version,
                    framework: $framework,
                    confidence: $this->calculateConfidence($files, 'ruby', $framework),
                    detectedFiles: $files,
                );
            }

            // Check for Node.js
            if ($this->isNodeRepository($files)) {
                $framework = $this->detectNodeFramework($files);
                $version = $this->detectNodeVersion($files);

                return new RuntimeProfile(
                    runtime: 'node',
                    runtimeVersion: $version,
                    framework: $framework,
                    confidence: $this->calculateConfidence($files, 'node', $framework),
                    detectedFiles: $files,
                );
            }

            // Check for Python
            if ($this->isPythonRepository($files)) {
                $framework = $this->detectPythonFramework($files);
                $version = $this->detectPythonVersion($files);

                return new RuntimeProfile(
                    runtime: 'python',
                    runtimeVersion: $version,
                    framework: $framework,
                    confidence: $this->calculateConfidence($files, 'python', $framework),
                    detectedFiles: $files,
                );
            }

            // Default to generic PHP if we can't determine
            return new RuntimeProfile(
                runtime: 'php',
                runtimeVersion: '8.3',
                framework: 'generic',
                confidence: 'low',
                detectedFiles: $files,
                warnings: ['Could not determine runtime from repository files. Defaulting to PHP 8.3.'],
            );

        } catch (\Throwable $e) {
            Log::error('Runtime detection failed', [
                'repository' => $repositoryUrl,
                'error' => $e->getMessage(),
            ]);

            return new RuntimeProfile(
                runtime: 'php',
                runtimeVersion: '8.3',
                framework: 'generic',
                confidence: 'unknown',
                detectedFiles: [],
                warnings: ['Failed to analyze repository: '.$e->getMessage()],
            );
        }
    }

    /**
     * Detect runtime from a CloudApp model.
     */
    public function detectFromApp(CloudApp $app): RuntimeProfile
    {
        return $this->detect($app->git_repository_url, $app->git_branch);
    }

    /**
     * Fetch key repository files for analysis.
     *
     * Uses GitHub/GitLab API to fetch file listings without full clone.
     */
    private function fetchRepositoryFiles(string $repositoryUrl, string $branch): array
    {
        $files = [];

        // Try GitHub API first
        if (str_contains($repositoryUrl, 'github.com')) {
            $files = $this->fetchFromGitHubApi($repositoryUrl, $branch);
        }

        // Try GitLab API
        if (empty($files) && str_contains($repositoryUrl, 'gitlab.com')) {
            $files = $this->fetchFromGitLabApi($repositoryUrl, $branch);
        }

        // Fallback: try to clone briefly and list files
        if (empty($files)) {
            $files = $this->fetchViaSparseCheckout($repositoryUrl, $branch);
        }

        return $files;
    }

    /**
     * Fetch repository file list from GitHub API.
     */
    private function fetchFromGitHubApi(string $repositoryUrl, string $branch): array
    {
        try {
            $parsed = $this->parseGitHubUrl($repositoryUrl);
            if (!$parsed) {
                return [];
            }

            $apiUrl = "https://api.github.com/repos/{$parsed['owner']}/{$parsed['repo']}/contents/";
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'dply-cloud-runtime-detector',
            ])->get($apiUrl, ['ref' => $branch]);

            if (!$response->successful()) {
                return [];
            }

            $items = $response->json() ?? [];
            $files = [];

            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $files[$item['name']] = [
                        'name' => $item['name'],
                        'path' => $item['path'],
                        'size' => $item['size'] ?? 0,
                    ];

                    // Fetch content for key files
                    if (in_array($item['name'], $this->getKeyFilenames(), true)) {
                        try {
                            $contentResponse = Http::withHeaders([
                                'Accept' => 'application/vnd.github.v3.raw',
                                'User-Agent' => 'dply-cloud-runtime-detector',
                            ])->get($item['download_url']);

                            if ($contentResponse->successful()) {
                                $files[$item['name']]['content'] = $contentResponse->body();
                            }
                        } catch (\Throwable $e) {
                            // Continue without content
                        }
                    }
                }
            }

            return $files;

        } catch (\Throwable $e) {
            Log::warning('GitHub API fetch failed', [
                'url' => $repositoryUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch repository file list from GitLab API.
     */
    private function fetchFromGitLabApi(string $repositoryUrl, string $branch): array
    {
        try {
            $parsed = $this->parseGitLabUrl($repositoryUrl);
            if (!$parsed) {
                return [];
            }

            $projectPath = urlencode($parsed['path']);
            $apiUrl = "https://gitlab.com/api/v4/projects/{$projectPath}/repository/tree";

            $response = Http::withHeaders([
                'User-Agent' => 'dply-cloud-runtime-detector',
            ])->get($apiUrl, [
                'ref' => $branch,
                'per_page' => 100,
            ]);

            if (!$response->successful()) {
                return [];
            }

            $items = $response->json() ?? [];
            $files = [];

            foreach ($items as $item) {
                if ($item['type'] === 'blob') {
                    $files[$item['name']] = [
                        'name' => $item['name'],
                        'path' => $item['path'],
                    ];

                    // Fetch content for key files
                    if (in_array($item['name'], $this->getKeyFilenames(), true)) {
                        try {
                            $contentUrl = "https://gitlab.com/api/v4/projects/{$projectPath}/repository/files/".
                                urlencode($item['path']).'/raw';
                            $contentResponse = Http::withHeaders([
                                'User-Agent' => 'dply-cloud-runtime-detector',
                            ])->get($contentUrl, ['ref' => $branch]);

                            if ($contentResponse->successful()) {
                                $files[$item['name']]['content'] = $contentResponse->body();
                            }
                        } catch (\Throwable $e) {
                            // Continue without content
                        }
                    }
                }
            }

            return $files;

        } catch (\Throwable $e) {
            Log::warning('GitLab API fetch failed', [
                'url' => $repositoryUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Use git sparse checkout to fetch repository structure.
     */
    private function fetchViaSparseCheckout(string $repositoryUrl, string $branch): array
    {
        $tempDir = sys_get_temp_dir().'/dply-runtime-detection-'.uniqid();

        try {
            // Create temporary directory
            if (!mkdir($tempDir, 0700, true)) {
                throw new \RuntimeException('Failed to create temp directory');
            }

            // Initialize git repo with sparse checkout
            $commands = [
                'cd '.escapeshellarg($tempDir),
                'git init',
                'git config core.sparseCheckout true',
                'echo "*.json" > .git/info/sparse-checkout',
                'echo "Gemfile" >> .git/info/sparse-checkout',
                'echo "requirements.txt" >> .git/info/sparse-checkout',
                'echo "*.php" >> .git/info/sparse-checkout',
                'git remote add origin '.escapeshellarg($repositoryUrl),
                'git pull --depth=1 origin '.escapeshellarg($branch),
            ];

            $output = [];
            $returnVar = 0;
            exec(implode(' && ', $commands).' 2>&1', $output, $returnVar);

            if ($returnVar !== 0) {
                throw new \RuntimeException('Git fetch failed: '.implode("\n", $output));
            }

            // List files
            $files = [];
            $dirIterator = new \DirectoryIterator($tempDir);

            foreach ($dirIterator as $fileInfo) {
                if ($fileInfo->isFile() && !$fileInfo->isDot()) {
                    $name = $fileInfo->getFilename();
                    $files[$name] = [
                        'name' => $name,
                        'path' => $name,
                        'size' => $fileInfo->getSize(),
                    ];

                    // Read content for key files
                    if (in_array($name, $this->getKeyFilenames(), true)) {
                        $files[$name]['content'] = file_get_contents($fileInfo->getPathname());
                    }
                }
            }

            return $files;

        } finally {
            // Cleanup
            if (is_dir($tempDir)) {
                $this->recursiveRmdir($tempDir);
            }
        }
    }

    /**
     * List of key filenames to fetch content for.
     *
     * @return list<string>
     */
    private function getKeyFilenames(): array
    {
        return [
            'composer.json',
            'Gemfile',
            'Gemfile.lock',
            'package.json',
            'requirements.txt',
            'Pipfile',
            'pyproject.toml',
            'Dockerfile',
            'index.php',
            'artisan',
            'config.ru',
            'Rakefile',
            'app.rb',
            'server.js',
            'app.js',
            '.ruby-version',
            '.node-version',
            '.nvmrc',
            '.php-version',
        ];
    }

    /**
     * Parse GitHub URL to extract owner and repo.
     *
     * @return array{owner: string, repo: string}|null
     */
    private function parseGitHubUrl(string $url): ?array
    {
        if (preg_match('#github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => $matches[2],
            ];
        }

        return null;
    }

    /**
     * Parse GitLab URL to extract project path.
     *
     * @return array{path: string}|null
     */
    private function parseGitLabUrl(string $url): ?array
    {
        if (preg_match('#gitlab\.com/(.+?)(?:\.git)?$#', $url, $matches)) {
            return [
                'path' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * Check if repository is a PHP project.
     */
    private function isPhpRepository(array $files): bool
    {
        return isset($files['composer.json'])
            || isset($files['index.php'])
            || isset($files['artisan'])
            || isset($files['.php-version']);
    }

    /**
     * Detect PHP framework from repository files.
     */
    private function detectPhpFramework(array $files): string
    {
        if (!isset($files['composer.json']['content'])) {
            return 'generic';
        }

        $content = $files['composer.json']['content'];
        $json = json_decode($content, true);

        if (!$json || !isset($json['require'])) {
            return 'generic';
        }

        $requires = $json['require'];

        // Check for Laravel
        if (isset($requires['laravel/framework']) || isset($requires['laravel/laravel'])) {
            return 'laravel';
        }

        // Check for Symfony
        if (isset($requires['symfony/framework-bundle']) || isset($requires['symfony/symfony'])) {
            return 'symfony';
        }

        // Check for WordPress
        if (isset($requires['johnpbloch/wordpress']) || isset($requires['roots/wordpress'])) {
            return 'wordpress';
        }

        return 'generic';
    }

    /**
     * Detect PHP version from repository files.
     */
    private function detectPhpVersion(array $files): string
    {
        // Check .php-version file
        if (isset($files['.php-version']['content'])) {
            $version = trim($files['.php-version']['content']);
            if (preg_match('/^8\.[0-4]$/', $version)) {
                return $version;
            }
        }

        // Check composer.json
        if (isset($files['composer.json']['content'])) {
            $json = json_decode($files['composer.json']['content'], true);

            // Check require.php
            if (isset($json['require']['php'])) {
                $phpConstraint = $json['require']['php'];
                // Extract version from constraint like "^8.1" or ">=8.2"
                if (preg_match('/(\d+\.\d+)/', $phpConstraint, $matches)) {
                    $version = $matches[1];
                    // Map to supported versions
                    if (version_compare($version, '8.4', '>=')) {
                        return '8.4';
                    }
                    if (version_compare($version, '8.3', '>=')) {
                        return '8.3';
                    }
                    if (version_compare($version, '8.2', '>=')) {
                        return '8.2';
                    }
                    if (version_compare($version, '8.1', '>=')) {
                        return '8.1';
                    }
                }
            }

            // Check config.platform.php
            if (isset($json['config']['platform']['php'])) {
                $version = $json['config']['platform']['php'];
                if (preg_match('/^(\d+\.\d+)/', $version, $matches)) {
                    return $matches[1];
                }
            }
        }

        // Default to latest supported
        return '8.3';
    }

    /**
     * Check if repository is a Ruby project.
     */
    private function isRubyRepository(array $files): bool
    {
        return isset($files['Gemfile'])
            || isset($files['Gemfile.lock'])
            || isset($files['config.ru'])
            || isset($files['Rakefile'])
            || isset($files['.ruby-version']);
    }

    /**
     * Detect Ruby framework from repository files.
     */
    private function detectRubyFramework(array $files): string
    {
        // Check Gemfile for Rails
        if (isset($files['Gemfile']['content'])) {
            $content = $files['Gemfile']['content'];
            if (str_contains($content, "gem 'rails'") || str_contains($content, 'gem "rails"')) {
                return 'rails';
            }
            if (str_contains($content, "gem 'sinatra'") || str_contains($content, 'gem "sinatra"')) {
                return 'sinatra';
            }
        }

        // Check for config.ru (common for Rack-based apps)
        if (isset($files['config.ru'])) {
            return 'rack';
        }

        return 'generic';
    }

    /**
     * Detect Ruby version from repository files.
     */
    private function detectRubyVersion(array $files): string
    {
        // Check .ruby-version
        if (isset($files['.ruby-version']['content'])) {
            $version = trim($files['.ruby-version']['content']);
            if (preg_match('/^(\d+\.\d+)/', $version, $matches)) {
                return $matches[1];
            }
        }

        // Check Gemfile
        if (isset($files['Gemfile']['content'])) {
            if (preg_match('/ruby\s+[\'"]?([\d.]+)[\'"]?/', $files['Gemfile']['content'], $matches)) {
                $version = $matches[1];
                if (version_compare($version, '3.3', '>=')) {
                    return '3.3';
                }
                if (version_compare($version, '3.2', '>=')) {
                    return '3.2';
                }
            }
        }

        // Default to latest supported
        return '3.3';
    }

    /**
     * Check if repository is a Node.js project.
     */
    private function isNodeRepository(array $files): bool
    {
        return isset($files['package.json'])
            || isset($files['package-lock.json'])
            || isset($files['yarn.lock'])
            || isset($files['.nvmrc'])
            || isset($files['.node-version']);
    }

    /**
     * Detect Node.js framework from repository files.
     */
    private function detectNodeFramework(array $files): string
    {
        if (!isset($files['package.json']['content'])) {
            return 'generic';
        }

        $json = json_decode($files['package.json']['content'], true);
        if (!$json) {
            return 'generic';
        }

        $dependencies = array_merge(
            $json['dependencies'] ?? [],
            $json['devDependencies'] ?? []
        );

        if (isset($dependencies['next'])) {
            return 'nextjs';
        }
        if (isset($dependencies['nuxt'])) {
            return 'nuxt';
        }
        if (isset($dependencies['express'])) {
            return 'express';
        }
        if (isset($dependencies['fastify'])) {
            return 'fastify';
        }
        if (isset($dependencies['koa'])) {
            return 'koa';
        }

        return 'generic';
    }

    /**
     * Detect Node.js version from repository files.
     */
    private function detectNodeVersion(array $files): string
    {
        // Check .nvmrc or .node-version
        foreach (['.nvmrc', '.node-version'] as $file) {
            if (isset($files[$file]['content'])) {
                $version = trim($files[$file]['content']);
                if (preg_match('/^(\d+)/', $version, $matches)) {
                    $major = $matches[1];
                    if ($major === '22' || $major === '20') {
                        return $major;
                    }
                }
            }
        }

        // Check package.json engines
        if (isset($files['package.json']['content'])) {
            $json = json_decode($files['package.json']['content'], true);
            if (isset($json['engines']['node'])) {
                $engine = $json['engines']['node'];
                if (preg_match('/(\d+)/', $engine, $matches)) {
                    $major = $matches[1];
                    if ($major === '22' || $major === '20') {
                        return $major;
                    }
                }
            }
        }

        // Default to latest LTS
        return '22';
    }

    /**
     * Check if repository is a Python project.
     */
    private function isPythonRepository(array $files): bool
    {
        return isset($files['requirements.txt'])
            || isset($files['Pipfile'])
            || isset($files['pyproject.toml'])
            || isset($files['setup.py'])
            || isset($files['app.py']);
    }

    /**
     * Detect Python framework from repository files.
     */
    private function detectPythonFramework(array $files): string
    {
        $filesToCheck = ['requirements.txt', 'Pipfile', 'pyproject.toml'];

        foreach ($filesToCheck as $filename) {
            if (isset($files[$filename]['content'])) {
                $content = strtolower($files[$filename]['content']);
                if (str_contains($content, 'django')) {
                    return 'django';
                }
                if (str_contains($content, 'flask')) {
                    return 'flask';
                }
                if (str_contains($content, 'fastapi')) {
                    return 'fastapi';
                }
            }
        }

        return 'generic';
    }

    /**
     * Detect Python version from repository files.
     */
    private function detectPythonVersion(array $files): string
    {
        // Check pyproject.toml
        if (isset($files['pyproject.toml']['content'])) {
            if (preg_match('/requires-python\s*=\s*[\'"]?>=?(\d+\.\d+)/', $files['pyproject.toml']['content'], $matches)) {
                $version = $matches[1];
                if (version_compare($version, '3.12', '>=')) {
                    return '3.12';
                }
                if (version_compare($version, '3.11', '>=')) {
                    return '3.11';
                }
            }
        }

        // Default to latest supported
        return '3.12';
    }

    /**
     * Calculate confidence level based on detection evidence.
     */
    private function calculateConfidence(array $files, string $runtime, ?string $framework): string
    {
        $score = 0;

        // Runtime indicators
        $indicators = [
            'php' => ['composer.json', 'artisan', 'index.php'],
            'ruby' => ['Gemfile', 'config.ru', 'Rakefile'],
            'node' => ['package.json'],
            'python' => ['requirements.txt', 'Pipfile', 'pyproject.toml'],
        ];

        foreach ($indicators[$runtime] ?? [] as $file) {
            if (isset($files[$file])) {
                $score += 1;
            }
        }

        // Framework indicators increase confidence
        if ($framework && $framework !== 'generic') {
            $score += 2;
        }

        // Version file increases confidence
        $versionFiles = ['.ruby-version', '.node-version', '.php-version', '.python-version'];
        foreach ($versionFiles as $file) {
            if (isset($files[$file])) {
                $score += 1;
                break;
            }
        }

        return match (true) {
            $score >= 4 => 'high',
            $score >= 2 => 'medium',
            $score >= 1 => 'low',
            default => 'unknown',
        };
    }

    /**
     * Recursively remove a directory.
     */
    private function recursiveRmdir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->recursiveRmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

/**
 * Value object representing detected runtime profile.
 */
class RuntimeProfile
{
    public function __construct(
        public readonly string $runtime,
        public readonly string $runtimeVersion,
        public readonly string $framework,
        public readonly string $confidence,
        public readonly array $detectedFiles = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * Get the full runtime string for CloudApp (e.g., "php-8.3").
     */
    public function runtimeString(): string
    {
        return $this->runtime.'-'.$this->runtimeVersion;
    }

    /**
     * Check if detection was confident enough to proceed.
     */
    public function isConfident(): bool
    {
        return in_array($this->confidence, ['high', 'medium'], true);
    }

    /**
     * Convert to array for storage/JSON.
     */
    public function toArray(): array
    {
        return [
            'runtime' => $this->runtime,
            'runtime_version' => $this->runtimeVersion,
            'framework' => $this->framework,
            'confidence' => $this->confidence,
            'detected_files' => array_keys($this->detectedFiles),
            'warnings' => $this->warnings,
        ];
    }
}
