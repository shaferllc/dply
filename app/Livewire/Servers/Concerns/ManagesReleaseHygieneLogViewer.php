<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerSystemLogReader;

/**
 * Inline log tail viewer for release hygiene (system + Laravel logs from scan).
 */
trait ManagesReleaseHygieneLogViewer
{
    public bool $showHygieneLogModal = false;

    public ?string $hygieneLogPath = null;

    public ?string $hygieneLogLabel = null;

    public ?string $hygieneLogOutput = null;

    public ?string $hygieneLogError = null;

    public int $hygieneLogTailLines = 200;

    public function mountReleaseHygieneLogViewer(): void
    {
        $this->hygieneLogTailLines = max(
            50,
            min(5000, (int) config('server_release_hygiene.log_tail_lines', 200)),
        );
    }

    public function viewHygieneLog(string $path, string $label): void
    {
        $this->authorize('view', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot read server log files over SSH.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Provisioning and SSH must be ready before reading files on the server.'));

            return;
        }

        try {
            $this->assertHygieneLogPathAllowed($path);
        } catch (\InvalidArgumentException) {
            $this->toastError(__('That log path is not available from the hygiene scan.'));

            return;
        }

        $this->hygieneLogPath = $path;
        $this->hygieneLogLabel = $label;
        $this->showHygieneLogModal = true;
        $this->fetchHygieneLog();
    }

    public function refreshHygieneLog(): void
    {
        if (! $this->showHygieneLogModal || $this->hygieneLogPath === null) {
            return;
        }

        $this->fetchHygieneLog();
    }

    public function closeHygieneLogModal(): void
    {
        $this->showHygieneLogModal = false;
        $this->hygieneLogPath = null;
        $this->hygieneLogLabel = null;
        $this->hygieneLogOutput = null;
        $this->hygieneLogError = null;
    }

    protected function fetchHygieneLog(): void
    {
        $path = $this->hygieneLogPath;
        if ($path === null || $path === '') {
            return;
        }

        $this->authorize('view', $this->server);

        $budget = (int) config('server_system_logs.request_time_budget_seconds', 90);
        if ($budget > 0) {
            @set_time_limit($budget);
        }

        $lines = max(50, min(5000, (int) $this->hygieneLogTailLines));
        $this->hygieneLogTailLines = $lines;

        try {
            $this->assertHygieneLogPathAllowed($path);
        } catch (\InvalidArgumentException) {
            $this->hygieneLogOutput = null;
            $this->hygieneLogError = __('That log path is not available from the hygiene scan.');

            return;
        }

        $result = app(ServerSystemLogReader::class)->tailAllowlistedFile(
            $this->server->fresh(),
            $path,
            $lines,
        );

        $raw = (string) ($result['output'] ?? '');
        $maxStored = (int) config('server_system_logs.max_stored_bytes', 524288);
        if ($maxStored > 0 && strlen($raw) > $maxStored) {
            $raw = '[dply] '.__('Output truncated for the UI.')."\n\n".substr($raw, 0, $maxStored);
        }

        $this->hygieneLogOutput = $raw;
        $this->hygieneLogError = $result['error'] ?? null;
    }

    protected function assertHygieneLogPathAllowed(string $path): void
    {
        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException;
        }

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $snapshot = is_array($meta['release_hygiene_snapshot'] ?? null) ? $meta['release_hygiene_snapshot'] : [];

        foreach ($snapshot['system']['logfiles'] ?? [] as $file) {
            if (is_array($file) && ($file['path'] ?? '') === $normalized) {
                return;
            }
        }

        foreach ($snapshot['sites'] ?? [] as $site) {
            if (! is_array($site)) {
                continue;
            }
            if (($site['laravel_log_path'] ?? '') === $normalized) {
                return;
            }
        }

        foreach (config('server_system_logs.allowed_path_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException;
    }
}
