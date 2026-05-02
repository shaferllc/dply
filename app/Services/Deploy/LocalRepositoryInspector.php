<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Enums\SiteType;
use Illuminate\Support\Str;

class LocalRepositoryInspector
{
    public function __construct(
        private readonly ServerlessRepositoryCheckout $repositoryCheckout,
        private readonly LocalRuntimeDetector $runtimeDetector,
    ) {}

    /**
     * @return array{
     *     repository_url: string,
     *     repository_branch: string,
     *     repository_subdirectory: string,
     *     slug: string,
     *     name: string,
     *     inspection_output: string,
     *     detection: array{
     *         target_runtime: 'docker_web'|'kubernetes_web',
     *         target_kind: 'docker'|'kubernetes',
     *         site_type: SiteType,
     *         framework: string,
     *         language: string,
     *         confidence: string,
     *         document_root: string,
     *         repository_path: string,
     *         app_port: ?int,
     *         kubernetes_namespace: ?string,
     *         reasons: list<string>,
     *         warnings: list<string>,
     *         detected_files: list<string>,
     *         env_template: array{path: ?string, keys: list<string>}
     *     }
     * }
     */
    public function inspect(
        string $repositoryUrl,
        string $branch = 'main',
        string $subdirectory = '',
        int|string|null $userId = null,
        ?string $sourceControlAccountId = null,
    ): array {
        $repositoryUrl = trim($repositoryUrl);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        $subdirectory = trim($subdirectory, '/');
        $slug = $this->repoSlug($repositoryUrl);
        $workspacePath = null;

        try {
            $checkout = $this->repositoryCheckout->checkout(
                workspaceKey: 'local-launch-'.md5($repositoryUrl.'|'.$branch.'|'.(string) $userId),
                repositoryUrl: $repositoryUrl,
                branch: $branch,
                subdirectory: $subdirectory,
                userId: $userId,
                sourceControlAccountId: $sourceControlAccountId,
            );

            $workspacePath = $checkout['workspace_path'];
            $detection = $this->runtimeDetector->detect($checkout['working_directory'], $slug);

            return [
                'repository_url' => $repositoryUrl,
                'repository_branch' => $checkout['branch'],
                'repository_subdirectory' => $subdirectory,
                'slug' => $slug,
                'name' => Str::title(str_replace('-', ' ', $slug)),
                'inspection_output' => $checkout['output'],
                'detection' => $detection,
            ];
        } finally {
            if (is_string($workspacePath) && $workspacePath !== '') {
                $this->repositoryCheckout->cleanup($workspacePath);
            }
        }
    }

    private function repoSlug(string $repositoryUrl): string
    {
        $path = parse_url($repositoryUrl, PHP_URL_PATH);
        $basename = is_string($path) ? pathinfo($path, PATHINFO_FILENAME) : '';
        $slug = Str::slug($basename ?: 'local-project');

        return $slug !== '' ? $slug : 'local-project';
    }
}
