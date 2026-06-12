<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCreateFormFields
{
    public function updatedFormPrimaryHostname(string $value): void
    {
        $this->form->primary_hostname = strtolower(trim($value));
        $this->form->applyPathDefaults();
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->form->applyFunctionsDefaults();
        }
    }

    public function updatedFormCustomizePaths(bool $value): void
    {
        $this->form->customize_paths = $value;

        if (! $value) {
            $this->form->applyPathDefaults();
        }
    }

    public function updatedFormRuntime(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    public function updatedFormRuntimeVersion(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    public function updatedFormBuildCommand(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    public function updatedFormStartCommand(): void
    {
        $this->runtimeOverridesTouched = true;
    }
}
