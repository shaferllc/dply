<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Enums\SiteType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class LocalRuntimeDetector
{
    public function __construct(
        private readonly ServerlessRuntimeDetector $serverlessRuntimeDetector,
    ) {}

    /**
     * @return array{
     *     target_runtime: 'docker_web'|'kubernetes_web',
     *     target_kind: 'docker'|'kubernetes',
     *     site_type: SiteType,
     *     framework: string,
     *     language: string,
     *     confidence: string,
     *     document_root: string,
     *     repository_path: string,
     *     app_port: ?int,
     *     kubernetes_namespace: ?string,
     *     reasons: list<string>,
     *     warnings: list<string>,
     *     detected_files: list<string>,
     *     env_template: array{
     *         path: ?string,
     *         keys: list<string>
     *     }
     * }
     */
    public function detect(string $workingDirectory, string $slug): array
    {
        $appDetection = $this->serverlessRuntimeDetector->detect($workingDirectory, [
            'supports_php_runtime' => true,
            'supports_node_runtime' => true,
            'default_runtime' => 'nodejs:20',
            'default_entrypoint' => 'index',
            'default_package' => 'default',
        ]);

        $containerSignals = $this->detectContainerSignals($workingDirectory);
        $targetKind = $containerSignals['target_kind'] ?? 'docker';
        $siteType = $this->inferSiteType($appDetection);

        $reasons = array_values(array_unique(array_merge(
            $containerSignals['reasons'],
            $appDetection['reasons'] ?? [],
        )));

        $warnings = array_values(array_unique(array_merge(
            $containerSignals['warnings'],
            $appDetection['warnings'] ?? [],
            $this->targetWarnings($targetKind, $appDetection['framework'] ?? 'unknown'),
        )));

        if (! isset($containerSignals['target_kind'])) {
            $reasons[] = 'Defaulted to Docker because no strong Kubernetes repository markers were detected.';
        }

        return [
            'target_runtime' => $targetKind === 'kubernetes' ? 'kubernetes_web' : 'docker_web',
            'target_kind' => $targetKind,
            'site_type' => $siteType,
            'framework' => (string) ($appDetection['framework'] ?? 'unknown'),
            'language' => (string) ($appDetection['language'] ?? 'unknown'),
            'confidence' => $this->mergeConfidence(
                (string) ($containerSignals['confidence'] ?? 'medium'),
                (string) ($appDetection['confidence'] ?? 'low'),
            ),
            'document_root' => $this->documentRootFor($siteType, $workingDirectory, $slug),
            'repository_path' => '/var/www/'.$slug,
            'app_port' => $this->inferAppPort($siteType, $workingDirectory),
            'kubernetes_namespace' => $targetKind === 'kubernetes'
                ? ($containerSignals['namespace'] ?? $slug)
                : null,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'detected_files' => $containerSignals['detected_files'],
            'env_template' => $this->detectEnvTemplate($workingDirectory),
        ];
    }

    /**
     * @return array{
     *     target_kind?: 'docker'|'kubernetes',
     *     namespace?: string,
     *     confidence: string,
     *     reasons: list<string>,
     *     warnings: list<string>,
     *     detected_files: list<string>
     * }
     */
    private function detectContainerSignals(string $workingDirectory): array
    {
        $detectedFiles = [];
        $reasons = [];
        $warnings = [];

        foreach (['Chart.yaml', 'kustomization.yaml', 'kustomization.yml'] as $file) {
            if (is_file($workingDirectory.'/'.$file)) {
                $detectedFiles[] = $file;
            }
        }

        foreach (['Dockerfile', 'docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'] as $file) {
            if (is_file($workingDirectory.'/'.$file)) {
                $detectedFiles[] = $file;
            }
        }

        $kubernetesManifestPath = $this->firstKubernetesManifest($workingDirectory);
        if ($kubernetesManifestPath !== null) {
            $detectedFiles[] = $this->relativePath($workingDirectory, $kubernetesManifestPath);
        }

        if ($this->hasKubernetesSignal($detectedFiles, $kubernetesManifestPath)) {
            $reasons[] = 'Detected Kubernetes repository markers such as Helm, kustomize, or manifest files.';

            return [
                'target_kind' => 'kubernetes',
                'namespace' => $this->namespaceFromManifest($kubernetesManifestPath),
                'confidence' => 'high',
                'reasons' => $reasons,
                'warnings' => $warnings,
                'detected_files' => array_values(array_unique($detectedFiles)),
            ];
        }

        if ($this->hasDockerSignal($detectedFiles)) {
            $reasons[] = 'Detected Docker repository markers such as a Dockerfile or Compose file.';

            return [
                'target_kind' => 'docker',
                'confidence' => 'high',
                'reasons' => $reasons,
                'warnings' => $warnings,
                'detected_files' => array_values(array_unique($detectedFiles)),
            ];
        }

        return [
            'confidence' => 'low',
            'reasons' => $reasons,
            'warnings' => $warnings,
            'detected_files' => [],
        ];
    }

    private function inferSiteType(array $appDetection): SiteType
    {
        return match ($appDetection['framework'] ?? null) {
            'vite_static', 'static' => SiteType::Static,
            'nextjs', 'nuxt', 'node_generic' => SiteType::Node,
            default => match ($appDetection['language'] ?? null) {
                'node' => SiteType::Node,
                'static' => SiteType::Static,
                default => SiteType::Php,
            },
        };
    }

    private function documentRootFor(SiteType $siteType, string $workingDirectory, string $slug): string
    {
        if ($siteType === SiteType::Php && is_dir($workingDirectory.'/public')) {
            return '/var/www/'.$slug.'/public';
        }

        return '/var/www/'.$slug;
    }

    private function inferAppPort(SiteType $siteType, string $workingDirectory): ?int
    {
        if ($siteType !== SiteType::Node) {
            return null;
        }

        $packageJson = $this->readJson($workingDirectory.'/package.json');
        $scripts = is_array($packageJson['scripts'] ?? null) ? $packageJson['scripts'] : [];

        foreach (['start', 'dev', 'preview'] as $scriptKey) {
            $script = (string) ($scripts[$scriptKey] ?? '');
            if (preg_match('/(?:--port[ =]|PORT=)(\d{2,5})/', $script, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        $dependencies = array_merge(
            $this->stringKeys($packageJson['dependencies'] ?? null),
            $this->stringKeys($packageJson['devDependencies'] ?? null),
        );

        if (in_array('next', $dependencies, true) || in_array('nuxt', $dependencies, true)) {
            return 3000;
        }

        return 3000;
    }

    /**
     * @return array{path: ?string, keys: list<string>}
     */
    private function detectEnvTemplate(string $workingDirectory): array
    {
        foreach (['.env.example', '.env.local.example', '.env.dist', '.env'] as $file) {
            $path = $workingDirectory.'/'.$file;
            if (! is_file($path)) {
                continue;
            }

            preg_match_all('/^\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents($path), $matches);

            return [
                'path' => $file,
                'keys' => array_values(array_unique($matches[1] ?? [])),
            ];
        }

        return [
            'path' => null,
            'keys' => [],
        ];
    }

    private function mergeConfidence(string $containerConfidence, string $appConfidence): string
    {
        $scores = ['low' => 1, 'medium' => 2, 'high' => 3];
        $score = max($scores[$containerConfidence] ?? 1, $scores[$appConfidence] ?? 1);

        return array_search($score, $scores, true) ?: 'low';
    }

    /**
     * @return list<string>
     */
    private function targetWarnings(string $targetKind, string $framework): array
    {
        if ($targetKind === 'kubernetes') {
            return ['Kubernetes auto-detection is best-effort for now. Review namespace, manifests, and runtime details after the first launch.'];
        }

        return match ($framework) {
            'vite_static' => ['This repository looks like a Vite-style static app. Dply will build it into a static container automatically.'],
            'nextjs', 'nuxt' => ['Framework-specific Node repos may still need follow-up runtime tuning after the first auto-launch.'],
            default => [],
        };
    }

    private function hasDockerSignal(array $detectedFiles): bool
    {
        return collect($detectedFiles)->contains(static fn (string $file): bool => str_contains($file, 'Dockerfile')
            || str_contains($file, 'compose'));
    }

    private function hasKubernetesSignal(array $detectedFiles, ?string $manifestPath): bool
    {
        if ($manifestPath !== null) {
            return true;
        }

        return collect($detectedFiles)->contains(static fn (string $file): bool => in_array($file, [
            'Chart.yaml',
            'kustomization.yaml',
            'kustomization.yml',
        ], true));
    }

    private function firstKubernetesManifest(string $workingDirectory): ?string
    {
        foreach (File::allFiles($workingDirectory) as $file) {
            $relative = $this->relativePath($workingDirectory, $file->getPathname());
            if (! preg_match('/\.(ya?ml)$/', $relative)) {
                continue;
            }

            $contents = (string) $file->getContents();
            if (preg_match('/kind:\s*(Deployment|Service|Ingress|StatefulSet|DaemonSet|Job|CronJob)/i', $contents) === 1) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function namespaceFromManifest(?string $manifestPath): ?string
    {
        if ($manifestPath === null || ! is_file($manifestPath)) {
            return null;
        }

        $contents = (string) file_get_contents($manifestPath);
        if (preg_match('/namespace:\s*([a-z0-9-]+)/i', $contents, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): ?string => is_string($key) ? $key : null, array_keys($value))
        ));
    }

    private function relativePath(string $root, string $path): string
    {
        return ltrim(Str::replaceFirst(rtrim($root, '/'), '', $path), '/');
    }
}
