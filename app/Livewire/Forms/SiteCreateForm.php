<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class SiteCreateForm extends Form
{
    public const DEFAULT_DEPLOY_PATH = '/home/dply/app';

    public const DEFAULT_FUNCTIONS_BUILD_COMMAND = 'npm install && npm run build';

    public const DEFAULT_FUNCTIONS_ARTIFACT_OUTPUT_PATH = 'dist';

    public string $name = '';

    /**
     * Source mode for the new site:
     *  - 'import':   bring an existing repo (current default behaviour)
     *  - 'scaffold': spin up a fresh app from a starter template (Laravel / WordPress)
     *
     * Drives the wizard's "branch at step 1" toggle (Q3). Scaffold mode
     * collects fewer fields and dispatches a separate pipeline (PR 5/6).
     */
    public string $mode = 'import';

    /**
     * Starter template chosen when mode === 'scaffold'.
     * Empty for import mode. v1 supports 'laravel' and 'wordpress' (Q4).
     */
    public string $scaffold_framework = '';

    /**
     * Admin email collected on the scaffold-mode form. Used to seed the
     * first WordPress / Breeze user on a successful scaffold (Q11).
     */
    public string $scaffold_admin_email = '';

    public string $type = 'php';

    /**
     * VM deploy target: native stack (PHP-FPM / static / Node proxy) or Docker
     * container published on a host port and routed via the server webserver.
     */
    public string $deploy_stack = 'native';

    public string $document_root = '/home/dply/app/public';

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

    /**
     * Database engine override for multi-engine servers. Empty means "use
     * the server's default" — see {@see Site::databaseEngine()}. Set to
     * one of the engines from {@see ServerDatabaseEngine} on the target
     * server when the user picks a non-default engine.
     */
    public string $database_engine = '';

    /**
     * Framework hint chosen by the user in the workspace "Add site" modal.
     * Drives the default web directory and the legacy {@see $type}; the
     * runtime detector confirms/overrides this from the repo on clone.
     *
     * Recognised values: '' (None — static HTML or PHP), 'laravel',
     * 'nodejs', 'statamic', 'craft', 'symfony', 'wordpress', 'october',
     * 'cakephp3'.
     */
    public string $framework = '';

    /**
     * Webserver template slug. Reserved for future per-template Nginx
     * recipes (Forge parity); the default rendering is unchanged today.
     */
    public string $webserver_template = 'default';

    public bool $create_system_user = false;

    public bool $create_staging_site = false;

    public bool $use_as_redirect_domain = false;

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
        $this->document_root = $basePath.$this->frameworkWebSubdir();
    }

    /**
     * Resolve the framework choice to (type, web-subdir) so the modal can
     * mirror Forge's "Project type" → web-directory defaults. The runtime
     * detector confirms/overrides the type once the repo is cloned.
     */
    public function applyFrameworkDefaults(string $framework): void
    {
        $this->framework = $framework;

        $type = match ($framework) {
            'nodejs' => 'node',
            default => 'php',
        };

        $this->applyDefaultsForType($type);
    }

    private function frameworkWebSubdir(): string
    {
        if ($this->type !== 'php') {
            return '';
        }

        return match ($this->framework) {
            'wordpress', 'october' => '',
            'craft' => '/web',
            'cakephp3' => '/webroot',
            default => '/public',
        };
    }

    public function defaultDeployPath(): string
    {
        // Sites live at /home/dply/<domain>, using the primary hostname
        // verbatim (DNS-safe chars only) rather than a slug.
        $hostname = strtolower(trim($this->primary_hostname));
        $hostname = (string) preg_replace('/[^a-z0-9.-]+/', '', $hostname);
        $hostname = trim($hostname, '.-');

        return $hostname !== ''
            ? '/home/dply/'.$hostname
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
