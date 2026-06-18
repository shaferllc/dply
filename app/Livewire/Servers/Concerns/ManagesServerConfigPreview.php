<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerManageSshExecutor;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerConfigPreview
{
    public function previewConfig(string $key): void
    {
        $previews = config('server_manage.config_previews', []);
        $entry = $previews[$key] ?? null;
        if (! is_array($entry) || empty($entry['path'])) {
            $this->remote_error = __('Unknown configuration preview.');

            return;
        }

        $this->runConfigPreview('manage-config-preview:'.$key, (string) $entry['path']);
    }

    public function previewConfigPath(string $path): void
    {
        $taskName = 'manage-config-preview-path:'.substr(sha1($path), 0, 12);
        $this->runConfigPreview($taskName, $path);
    }

    protected function runConfigPreview(string $taskName, string $path): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot read configuration over SSH.');

            return;
        }

        try {
            $this->assertAllowlistedConfigPath($path);
        } catch (\InvalidArgumentException) {
            $this->remote_error = __('That path is not allowlisted.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('SSH must be ready before previewing configuration.');

            return;
        }

        set_time_limit(120);

        $max = (int) config('server_manage.config_preview_max_bytes', 48_000);
        $pathArg = escapeshellarg($path);
        $inline = <<<BASH
path={$pathArg}
max={$max}
if [[ -r "\$path" ]]; then
  head -c "\$max" "\$path" || true
else
  echo "Not found or not readable: \$path"
fi
BASH;

        try {
            $server = $this->server->fresh();
            $title = __('TaskRunner (SSH)').' — '.__('Configuration preview').': '.$path;
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript($server, $taskName, $inline, 120, null, $title);

                return;
            }

            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta($title, $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inline);
            $out = $this->runManageInlineBash(
                $server,
                $taskName,
                $inline,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );
            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        }
    }

    protected function assertAllowlistedConfigPath(string $path): void
    {
        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException;
        }

        foreach (config('server_manage.allowed_config_paths_exact', []) as $exact) {
            if ($normalized === $exact) {
                return;
            }
        }

        foreach (config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException;
    }
}
