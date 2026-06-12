<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Services\Servers\NginxAccessLogParser;
use App\Services\Servers\ServerManageSshExecutor;

/**
 * Concern extracted from {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesWebserverLogs
{
    /** Which log to read: 'access', 'error', or 'journal'. */
    public string $log_kind = 'access';

    /** Last fetched log buffer; rendered in a <pre> on the Logs tab. */
    public string $log_output = '';

    /** How many trailing lines the last fetch grabbed. */
    public int $log_lines = 300;

    /** When true, the Logs tab adds a wire:poll so the buffer refreshes every few seconds. */
    public bool $log_live = false;

    /** When true, force the raw text dump even for parseable nginx access logs. */
    public bool $log_raw = false;

    /**
     * Refresh the buffer the Logs tab renders. `$kind` is one of `access`,
     * `error`, or `journal` — the available choices depend on the engine
     * layout. Limited to 300 lines unless explicitly overridden.
     */
    public function refreshWebserverLog(?string $kind = null, ?int $lines = null): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }

        if ($kind !== null) {
            $this->log_kind = in_array($kind, ['access', 'error', 'journal'], true) ? $kind : 'access';
        }
        if ($lines !== null) {
            $this->log_lines = max(50, min(2000, $lines));
        }

        $layout = (array) config('server_manage.webserver_config_layout.'.$this->workspace_tab, []);
        $path = match ($this->log_kind) {
            'access' => $layout['access_log'] ?? null,
            'error' => $layout['error_log'] ?? null,
            'journal' => null,
            default => null,
        };

        try {
            if ($this->log_kind === 'journal' || $path === null) {
                $unit = (string) ($layout['journal_unit'] ?? $this->workspace_tab);
                $script = sprintf(
                    '(sudo -n journalctl --no-pager -eu %1$s -n %2$d 2>&1 || journalctl --no-pager -eu %1$s -n %2$d 2>&1)',
                    escapeshellarg($unit),
                    $this->log_lines,
                );
            } else {
                $script = sprintf(
                    '(sudo -n tail -n %1$d %2$s 2>&1 || tail -n %1$d %2$s 2>&1)',
                    $this->log_lines,
                    escapeshellarg($path),
                );
            }
            $out = $this->runManageInlineBash(
                $this->server,
                'webserver-log:'.$this->workspace_tab.':'.$this->log_kind,
                $script,
                function (string $type, string $buffer): void {},
                30,
            );
            $this->log_output = ServerManageSshExecutor::stripSshClientNoise($out->getBuffer());
        } catch (\Throwable $e) {
            $this->log_output = '[error] '.$e->getMessage();
        }
    }

    public function toggleWebserverLogLive(): void
    {
        $this->log_live = ! $this->log_live;
        if ($this->log_live) {
            $this->refreshWebserverLog();
        }
    }

    public function toggleWebserverLogRaw(): void
    {
        $this->log_raw = ! $this->log_raw;
    }

    /**
     * Structured view of the current access-log buffer for the Logs tab.
     *
     * Only engages for the nginx-family "combined" format and only on the
     * access log; everything else (error log, journal, non-combined engines,
     * or operator-forced raw mode) returns ['structured' => false] so the
     * blade falls back to the existing <pre> dump. The SSH fetch itself is
     * unchanged — this purely parses the already-fetched text.
     *
     * @return array<string, mixed>
     */
    public function getParsedAccessLogProperty(): array
    {
        $off = ['structured' => false, 'rows' => [], 'summary' => null];

        if ($this->log_raw || $this->log_kind !== 'access' || $this->log_output === '') {
            return $off;
        }

        // Combined format is the nginx default. Other engines (caddy JSON,
        // apache, haproxy, …) won't match looksLikeCombined() and fall back.
        $parser = app(NginxAccessLogParser::class);
        if (! $parser->looksLikeCombined($this->log_output)) {
            return $off;
        }

        $rows = $parser->parse($this->log_output);

        return [
            'structured' => true,
            'rows' => $rows,
            'summary' => $parser->summarize($rows),
        ];
    }

    protected function resetLogViewerState(): void
    {
        $this->log_kind = 'access';
        $this->log_output = '';
        $this->log_lines = 300;
        $this->log_live = false;
        $this->log_raw = false;
    }
}
