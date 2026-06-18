<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Support\Edge\EdgeRepoRoot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Detects monorepo markers at the repository root and lists likely
 * package directories for the Edge create-flow subdirectory picker.
 */
final class EdgeMonorepoDetector
{
    /** @var list<string> */
    private const MARKERS = [
        'pnpm-workspace.yaml',
        'pnpm-workspace.yml',
        'turbo.json',
        'nx.json',
        'lerna.json',
    ];

    /**
     * @return array{
     *     is_monorepo: bool,
     *     markers: list<string>,
     *     packages: list<array{path: string, label: string}>
     * }
     */
    /** @return array<string, mixed> */
    public function inspectDirectory(string $checkoutRoot): array
    {
        $root = rtrim($checkoutRoot, '/');
        if ($root === '' || ! is_dir($root)) {
            return [
                'is_monorepo' => false,
                'markers' => [],
                'packages' => [],
            ];
        }

        $markers = [];
        foreach (self::MARKERS as $marker) {
            if (is_file($root.'/'.$marker)) {
                $markers[] = $marker;
            }
        }

        $packages = $this->discoverPackages($root);
        $isMonorepo = $markers !== [] || count($packages) > 1;

        return [
            'is_monorepo' => $isMonorepo,
            'markers' => $markers,
            'packages' => $packages,
        ];
    }

    /**
     * Shallow-clones a repository branch and inspects the checkout root.
     *
     * @return array{
     *     is_monorepo: bool,
     *     markers: list<string>,
     *     packages: list<array{path: string, label: string}>
     * }
     */
    /** @return array<string, mixed> */
    public function inspectUrl(string $repositoryUrl, string $branch = 'main'): array
    {
        $repositoryUrl = trim($repositoryUrl);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        if ($repositoryUrl === '') {
            return [
                'is_monorepo' => false,
                'markers' => [],
                'packages' => [],
            ];
        }

        $workRoot = rtrim(sys_get_temp_dir(), '/').'/dply-edge-monorepo-'.bin2hex(random_bytes(8));
        $checkout = $workRoot.'/src';

        try {
            File::ensureDirectoryExists($workRoot);
            $clone = Process::timeout(120)->run([
                'git', 'clone', '--depth', '1', '--branch', $branch, $repositoryUrl, $checkout,
            ]);
            if (! $clone->successful()) {
                throw new RuntimeException(trim($clone->errorOutput()) ?: 'Git clone failed.');
            }

            return $this->inspectDirectory($checkout);
        } finally {
            if (is_dir($workRoot)) {
                File::deleteDirectory($workRoot);
            }
        }
    }

    /**
     * @return list<array{path: string, label: string}>
     */
    private function discoverPackages(string $root): array
    {
        $packages = [];

        if (is_file($root.'/package.json')) {
            $packages[] = [
                'path' => '',
                'label' => $this->packageLabel($root.'/package.json', 'Repository root'),
            ];
        }

        foreach (File::directories($root) as $directory) {
            $basename = basename($directory);
            if ($this->shouldSkipDirectory($basename)) {
                continue;
            }

            if (is_file($directory.'/package.json')) {
                $packages[] = [
                    'path' => EdgeRepoRoot::normalize($basename),
                    'label' => $this->packageLabel($directory.'/package.json', $basename),
                ];

                continue;
            }

            foreach (File::directories($directory) as $nested) {
                $nestedName = basename($nested);
                if ($this->shouldSkipDirectory($nestedName)) {
                    continue;
                }
                if (! is_file($nested.'/package.json')) {
                    continue;
                }

                $path = EdgeRepoRoot::normalize($basename.'/'.$nestedName);
                if ($path === '') {
                    continue;
                }

                $packages[] = [
                    'path' => $path,
                    'label' => $this->packageLabel($nested.'/package.json', $path),
                ];
            }
        }

        usort($packages, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values(array_values($packages))))))))))))))))))))))))))))))));
    }

    private function shouldSkipDirectory(string $name): bool
    {
        return in_array($name, [
            '.git',
            'node_modules',
            'vendor',
            'dist',
            'build',
            '.next',
            'coverage',
        ], true) || str_starts_with($name, '.');
    }

    private function packageLabel(string $packageJsonPath, string $fallback): string
    {
        try {
            $json = json_decode((string) file_get_contents($packageJsonPath), true, 512, JSON_THROW_ON_ERROR);
            $name = is_array($json) && is_string($json['name'] ?? null) ? trim($json['name']) : '';

            return $name !== '' ? $name : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
