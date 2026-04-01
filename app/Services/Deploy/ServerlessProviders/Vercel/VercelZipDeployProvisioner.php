<?php

declare(strict_types=1);

namespace App\Services\Deploy\ServerlessProviders\Vercel;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Services\Deploy\Support\ArtifactZipPathPrefix;
use App\Services\Deploy\Support\ProvisionerConfigReport;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

final class VercelZipDeployProvisioner implements ServerlessFunctionProvisioner
{
    /**
     * @param  array<int, string>  $ignoredZipPathPrefixes
     */
    public function __construct(
        private readonly string $defaultToken,
        private readonly string $defaultTeamId,
        private readonly string $defaultProjectId,
        private readonly string $defaultProjectName,
        private readonly string $zipPathPrefix,
        private readonly int $zipMaxBytes,
        private readonly int $maxZipEntries,
        private readonly int $maxUncompressedBytes,
        private readonly array $ignoredZipPathPrefixes = ['__MACOSX/'],
    ) {}

    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        $ctx = $this->resolveVercelContext($config);
        $effectivePrefix = ArtifactZipPathPrefix::resolve($this->zipPathPrefix, $config, 'vercel_deploy_zip_path_prefix');
        $this->assertZipPathUnderPrefix($artifactPath, $effectivePrefix);
        $this->assertZipFileWithinByteLimit($artifactPath);
        $files = $this->filesFromZip($artifactPath);

        $query = array_filter(['teamId' => $ctx['team_id']]);
        $url = 'https://api.vercel.com/v13/deployments';
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $body = ['files' => $files];
        if ($ctx['project_id'] !== '') {
            $body['project'] = $ctx['project_id'];
        } else {
            $body['name'] = $ctx['project_name'];
        }

        $response = Http::withToken($ctx['token'])
            ->timeout(300)
            ->acceptJson()
            ->post($url, $body);

        if (! $response->successful()) {
            throw new RuntimeException('Vercel: HTTP '.$response->status().' — '.$response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Vercel: unexpected deployment response.');
        }

        $deployId = isset($json['id']) ? (string) $json['id'] : '';
        if ($deployId === '') {
            throw new RuntimeException('Vercel: deployment response missing id.');
        }

        return [
            'function_arn' => sprintf('vercel:deployment:%s', $deployId),
            'revision_id' => $deployId,
            'provider' => 'vercel',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{token: string, team_id: string, project_id: string, project_name: string}
     */
    private function resolveVercelContext(array $config): array
    {
        $settings = [];
        if (isset($config['project']['settings']) && is_array($config['project']['settings'])) {
            $settings = $config['project']['settings'];
        }

        $creds = [];
        if (isset($config['credentials']) && is_array($config['credentials'])) {
            $creds = $config['credentials'];
        }

        $token = trim((string) ($creds['vercel_token'] ?? $creds['api_token'] ?? ''));
        if ($token === '') {
            $token = $this->defaultToken;
        }

        $teamId = trim((string) ($creds['vercel_team_id'] ?? $creds['team_id'] ?? $settings['vercel_team_id'] ?? ''));
        if ($teamId === '') {
            $teamId = $this->defaultTeamId;
        }

        $projectId = trim((string) ($creds['vercel_project_id'] ?? $creds['project_id'] ?? $settings['vercel_project_id'] ?? ''));
        if ($projectId === '') {
            $projectId = $this->defaultProjectId;
        }

        $projectName = trim((string) ($creds['vercel_project_name'] ?? $creds['project_name'] ?? $settings['vercel_project_name'] ?? ''));
        if ($projectName === '') {
            $projectName = $this->defaultProjectName;
        }

        if ($token === '') {
            throw new RuntimeException('Vercel token is required (set VERCEL_TOKEN or project credentials vercel_token / api_token).');
        }
        if ($projectId === '' && $projectName === '') {
            throw new RuntimeException('Vercel project id or project name is required (set VERCEL_PROJECT_ID or VERCEL_PROJECT_NAME, or project settings / credentials).');
        }

        return [
            'token' => $token,
            'team_id' => $teamId,
            'project_id' => $projectId,
            'project_name' => $projectName,
        ];
    }

    private function assertZipPathUnderPrefix(string $path, string $realEffectivePrefix): void
    {
        if (! str_ends_with(strtolower($path), '.zip')) {
            throw new RuntimeException('Vercel deploy requires artifact_path to be a .zip file.');
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException('Artifact zip must resolve under VERCEL_DEPLOY_ZIP_PATH_PREFIX.');
        }

        $prefixWithSep = rtrim($realEffectivePrefix, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($realPath !== $realEffectivePrefix && ! str_starts_with($realPath, $prefixWithSep)) {
            throw new RuntimeException('Artifact zip path escapes allowed prefix directory.');
        }
    }

    private function assertZipFileWithinByteLimit(string $path): void
    {
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Artifact zip is empty or unreadable.');
        }
        if ($size > $this->zipMaxBytes) {
            throw new RuntimeException('Artifact zip exceeds maximum size ('.$this->zipMaxBytes.' bytes).');
        }
    }

    /**
     * @return list<array{file: string, data: string, encoding: string}>
     */
    private function filesFromZip(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open zip artifact.');
        }

        $files = [];
        $totalUncompressed = 0;

        try {
            $count = $zip->numFiles;
            if ($count > $this->maxZipEntries) {
                throw new RuntimeException('Zip contains too many entries (max '.$this->maxZipEntries.').');
            }

            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $entryName = (string) $stat['name'];
                if (str_ends_with($entryName, '/')) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $entryName);
                if ($normalized === '' || str_contains($normalized, '..')) {
                    throw new RuntimeException('Invalid path inside zip: '.$entryName);
                }
                if ($this->shouldIgnoreZipEntry($normalized)) {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    throw new RuntimeException('Could not read zip entry: '.$entryName);
                }

                $totalUncompressed += strlen($content);
                if ($totalUncompressed > $this->maxUncompressedBytes) {
                    throw new RuntimeException('Zip uncompressed size exceeds limit ('.$this->maxUncompressedBytes.' bytes).');
                }

                $files[] = $this->vercelFilePayload($normalized, $content);
            }
        } finally {
            $zip->close();
        }

        if ($files === []) {
            throw new RuntimeException('Zip contains no deployable files.');
        }

        return $files;
    }

    private function shouldIgnoreZipEntry(string $normalizedPath): bool
    {
        foreach ($this->ignoredZipPathPrefixes as $prefix) {
            $prefix = str_replace('\\', '/', $prefix);
            if ($prefix !== '' && str_starts_with($normalizedPath, $prefix)) {
                return true;
            }
        }

        return basename($normalizedPath) === '.DS_Store';
    }

    /**
     * @return array{file: string, data: string, encoding: string}
     */
    private function vercelFilePayload(string $relativePath, string $content): array
    {
        if ($content !== '' && (mb_check_encoding($content, 'UTF-8') === false || str_contains($content, "\0"))) {
            return [
                'file' => $relativePath,
                'data' => base64_encode($content),
                'encoding' => 'base64',
            ];
        }

        return [
            'file' => $relativePath,
            'data' => $content,
            'encoding' => 'utf-8',
        ];
    }
}
