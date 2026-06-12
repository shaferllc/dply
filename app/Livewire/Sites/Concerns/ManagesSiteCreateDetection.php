<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Enums\SiteType;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Models\Site;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCreateDetection
{
    /**
     * Run URL-first runtime detection against the form's git URL + branch.
     * Thin wrapper over {@see DetectsRepositoryRuntime::runDetection()}; the
     * concern populates `$detectedPlan` and then calls
     * {@see applyDetectedRuntimePrefills()} to pre-fill the form.
     */
    public function detectFromRepository(): void
    {
        $this->runDetection(
            $this->form->git_repository_url,
            $this->form->git_branch,
        );
    }

    /**
     * Resolve the value to write to Site.database_engine. Returns null
     * (use server default at read time) when the user accepted the
     * server's default; returns the picked engine string when they
     * chose a non-default one.
     */
    private function resolveDatabaseEngineOverride(): ?string
    {
        $picked = trim($this->form->database_engine);
        if ($picked === '') {
            return null;
        }

        $default = $this->server->defaultDatabaseEngine();
        if ($default !== null && $default->engine === $picked) {
            return null;
        }

        return $picked;
    }

    /**
     * Whether the inline "Install <runtime> on this server" affordance
     * should appear in the detection panel. True when:
     *   - detection has produced a runtime,
     *   - that runtime is one mise can manage (not PHP, not static), and
     *   - the server hasn't already pinned it via meta.runtime_defaults.
     *
     * Exposed as a Livewire-magic computed property so the Blade panel
     * can call `$this->detectedRuntimeNeedsInstall` without an in-template
     *
     * @php block (Blade's compileString has trouble parsing block-form
     * @php with array literals containing 'php'/'static' string keys —
     * Livewire-side computation sidesteps that entirely).
     */
    public function getDetectedRuntimeNeedsInstallProperty(): bool
    {
        $runtime = (string) ($this->detectedPlan['runtime'] ?? '');
        if ($runtime === '' || in_array($runtime, ['php', 'static'], true)) {
            return false;
        }

        return ! $this->server->hasRuntimeInstalled($runtime);
    }

    /**
     * Trigger runtime installation on the current server using the
     * detected runtime + version. Used by the inline "Install <runtime>
     * on this server" affordance the panel surfaces when the detected
     * runtime is missing from `server->installedRuntimeKeys()`.
     */
    public function installDetectedRuntimeOnServer(InstallRuntimeOnServer $action): void
    {
        $this->authorize('update', $this->server);

        $runtime = (string) ($this->detectedPlan['runtime'] ?? '');
        $version = (string) ($this->detectedPlan['version'] ?? '');

        if ($runtime === '' || $version === '') {
            $this->runtimeInstallResult = [
                'ok' => false,
                'message' => __('Run detection first so we have a runtime + version to install.'),
            ];

            return;
        }

        try {
            $result = $action->execute($this->server, $runtime, $version);
        } catch (\Throwable $e) {
            $this->runtimeInstallResult = [
                'ok' => false,
                'runtime' => $runtime,
                'version' => $version,
                'message' => $e->getMessage(),
            ];

            return;
        }

        $this->server->refresh();

        $this->runtimeInstallResult = [
            'ok' => $result['installed'],
            'runtime' => $result['runtime'],
            'version' => $result['version'],
            'message' => $result['installed']
                ? __('Installed :runtime :version on this server.', ['runtime' => $runtime, 'version' => $version])
                : __('Skipped — runtime not eligible for mise-managed install.'),
        ];
    }

    /**
     * Map a detected runtime onto the existing {@see SiteType} enum. The
     * enum still drives a lot of legacy UI/provisioner branches; we'll
     * collapse it into the new `runtime` column in a follow-up. For
     * runtimes the enum doesn't yet model (python/ruby/go) we fall back
     * to "node" as the closest approximation — the new `runtime` column
     * carries the truth, and downstream provisioners read that.
     */
    private function mapRuntimeToLegacyType(string $runtime): string
    {
        return match ($runtime) {
            'php' => 'php',
            'static' => 'static',
            default => 'node',
        };
    }
}
