<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessSourceStash;
use Illuminate\Support\Facades\File;

/**
 * Produces the working directory a serverless build runs in, from whichever
 * source the site was created with.
 *
 * The build pipeline is git-specific for exactly one step. Past the checkout,
 * {@see DigitalOceanFunctionsArtifactBuilder} only ever passes a path around:
 * hooks, Bref injection, runtime detection, framework adapters, the zip, asset
 * publishing and the log drain all take a directory and none of them care how
 * it came to exist. So git is the *first step* of the pipeline rather than a
 * thread running through it, and swapping that step is all it takes to deploy
 * a folder that has no remote at all.
 *
 * Both branches return the identical shape, which is what lets an uploaded
 * folder inherit framework detection, adapters, build hooks, asset publishing
 * and artifact rollback with no further work.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
final class ServerlessSourceResolver
{
    public function __construct(
        private readonly ServerlessRepositoryCheckout $repositoryCheckout,
        private readonly ServerlessSourceStash $stash,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onOutput
     * @return array{
     *     workspace_path: string,
     *     repository_path: string,
     *     working_directory: string,
     *     output: string,
     *     branch: string
     * }
     */
    public function resolve(
        Site $site,
        string $workspaceKey,
        string $subdirectory = '',
        ?string $sourceControlAccountId = null,
        ?callable $onOutput = null,
    ): array {
        if ($this->sourceKindFor($site) === 'upload') {
            return $this->fromUpload($site, $workspaceKey, $subdirectory, $onOutput);
        }

        $repositoryUrl = trim((string) $site->git_repository_url);
        if ($repositoryUrl === '') {
            throw new \RuntimeException('Choose a repository before deploying this serverless site.');
        }

        return $this->repositoryCheckout->checkout(
            $workspaceKey,
            $repositoryUrl,
            (string) ($site->git_branch ?: 'main'),
            $subdirectory,
            $site->user_id,
            $sourceControlAccountId,
            $site->gitRefKind(),
            $onOutput,
        );
    }

    /**
     * `git` unless the site was explicitly created from an upload — so every
     * site that predates uploaded sources keeps its behaviour.
     */
    public function sourceKindFor(Site $site): string
    {
        $config = $site->serverlessConfig();

        return ($config['source_kind'] ?? 'git') === 'upload' ? 'upload' : 'git';
    }

    /**
     * @param  (callable(string): void)|null  $onOutput
     * @return array{workspace_path: string, repository_path: string, working_directory: string, output: string, branch: string}
     */
    private function fromUpload(
        Site $site,
        string $workspaceKey,
        string $subdirectory,
        ?callable $onOutput,
    ): array {
        $workspacePath = storage_path('app/serverless-repositories/'.$workspaceKey);
        $repositoryPath = $workspacePath.'/repo';

        File::ensureDirectoryExists($workspacePath);

        $message = 'Unpacking the uploaded project folder…';
        if ($onOutput !== null) {
            $onOutput($message."\n");
        }

        // Validates the archive again on the way out of the stash, then
        // unpacks. Anything unsafe throws here rather than reaching a build
        // step that would run npm/composer inside it.
        $this->stash->materialize('site-'.$site->id, $repositoryPath);

        $subdirectory = trim($subdirectory, '/');
        $workingDirectory = $subdirectory !== ''
            ? $repositoryPath.'/'.$subdirectory
            : $repositoryPath;

        if (! is_dir($workingDirectory)) {
            throw new \RuntimeException('The uploaded source has no directory "'.$subdirectory.'".');
        }

        return [
            'workspace_path' => $workspacePath,
            'repository_path' => $repositoryPath,
            'working_directory' => $workingDirectory,
            'output' => $message,
            // No commit to name. The workspace tab reads this, so it says what
            // is true rather than inventing a branch.
            'branch' => 'uploaded',
        ];
    }
}
