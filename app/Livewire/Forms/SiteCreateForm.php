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

    /**
     * Canonical runtime key (php / node / python / ruby / go / static).
     * When empty, the legacy {@see $type}-based logic continues to drive
     * site creation. When set, this is the runtime persisted on the Site
     * and a non-PHP/non-static value triggers an internal_port allocation.
     */
    public string $runtime = '';

    /**
     * Runtime version pin (e.g. "22.7.0", "^8.3", "1.22"). Replaces the
     * old {@see $php_version} as the authoritative version for non-PHP
     * runtimes; for PHP this can mirror php_version.
     */
    public string $runtime_version = '';

    /**
     * Build command to run in `releases/{id}/` after checkout. Optional —
     * empty means the runtime's default applies.
     */
    public string $build_command = '';

    /**
     * Long-running web command. Set for non-PHP/non-static runtimes;
     * ignored for PHP (FPM is implicit) and static (NGINX serves files).
     */
    public string $start_command = '';

    /**
     * Repository URL used by URL-first auto-detection. Distinct from the
     * functions-flow URL ({@see $functions_repository_url}); this one
     * drives detection for VM-style sites.
     */
    public string $git_repository_url = '';

    /**
     * Branch to clone for detection. Defaults to "main" — git clone with
     * an explicit branch fails fast when the branch doesn't exist, which
     * is the right UX for an exploratory paste-a-URL flow.
     */
    public string $git_branch = 'main';

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
