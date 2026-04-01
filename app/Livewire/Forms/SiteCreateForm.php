<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class SiteCreateForm extends Form
{
    public const DEFAULT_DEPLOY_PATH = '/var/www/app';

    public string $name = '';

    public string $type = 'php';

    public string $document_root = '/var/www/app/public';

    public string $repository_path = self::DEFAULT_DEPLOY_PATH;

    public string $php_version = '8.3';

    public ?int $app_port = 3000;

    public string $primary_hostname = '';

    public bool $customize_paths = false;

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
}
