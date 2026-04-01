<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class SiteCreateForm extends Form
{
    public const DEFAULT_DEPLOY_PATH = '/var/www/app';

    public const DEFAULT_FUNCTIONS_BUILD_COMMAND = 'npm install && npm run build';

    public const DEFAULT_FUNCTIONS_ARTIFACT_OUTPUT_PATH = 'dist';

    public string $name = '';

    public string $type = 'php';

    public string $document_root = '/var/www/app/public';

    public string $repository_path = self::DEFAULT_DEPLOY_PATH;

    public string $php_version = '8.3';

    public ?int $app_port = 3000;

    public string $primary_hostname = '';

    public bool $customize_paths = false;

    public string $functions_runtime = 'nodejs:18';

    public string $functions_artifact_path = '';

    public string $functions_entrypoint = 'index';

    public string $functions_repo_source = 'manual';

    public string $functions_source_control_account_id = '';

    public string $functions_repository_selection = '';

    public string $functions_repository_url = '';

    public string $functions_repository_branch = 'main';

    public string $functions_repository_subdirectory = '';

    public string $functions_build_command = self::DEFAULT_FUNCTIONS_BUILD_COMMAND;

    public string $functions_artifact_output_path = self::DEFAULT_FUNCTIONS_ARTIFACT_OUTPUT_PATH;

    public function applyDefaultsForType(string $type): void
    {
        $this->type = $type;
        $this->applyPathDefaults();

        if ($type === 'php') {
            $this->app_port = null;

            return;
        }

        if ($type === 'node') {
            $this->app_port ??= 3000;

            return;
        }

        $this->app_port = null;
    }

    public function applyPathDefaults(): void
    {
        if ($this->customize_paths) {
            return;
        }

        $basePath = $this->defaultDeployPath();
        $this->repository_path = $basePath;
        $this->document_root = $this->type === 'php'
            ? $basePath.'/public'
            : $basePath;
    }

    public function defaultDeployPath(): string
    {
        $hostname = strtolower(trim($this->primary_hostname));

        if ($hostname === '') {
            return self::DEFAULT_DEPLOY_PATH;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $hostname);
        $slug = trim((string) $slug, '-');

        return $slug !== ''
            ? '/var/www/'.$slug
            : self::DEFAULT_DEPLOY_PATH;
    }

    public function applyFunctionsDefaults(): void
    {
        $this->functions_repository_branch = $this->functions_repository_branch !== '' ? $this->functions_repository_branch : 'main';
        $this->functions_build_command = $this->functions_build_command !== ''
            ? $this->functions_build_command
            : self::DEFAULT_FUNCTIONS_BUILD_COMMAND;
        $this->functions_artifact_output_path = $this->functions_artifact_output_path !== ''
            ? $this->functions_artifact_output_path
            : self::DEFAULT_FUNCTIONS_ARTIFACT_OUTPUT_PATH;
    }

    public function defaultArtifactBasename(): string
    {
        $hostname = strtolower(trim($this->primary_hostname));
        if ($hostname === '') {
            return 'site';
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $hostname);
        $slug = trim((string) $slug, '-');

        return $slug !== '' ? $slug : 'site';
    }
}
