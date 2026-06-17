<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use App\Models\Server;
use App\Models\Site;
use App\Services\Remediations\RemediationActionInterface;
use App\Services\Remediations\RemediationCatalog;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Apply a catalog remediation action over SSH. v1 runs `script` actions (the
 * declarative path); `handler` actions are a documented follow-on. Streams to a
 * ConsoleAction on the site so the deploy console / Errors view can show live
 * progress, and (on success) the operator can re-run the failed operation.
 */
class ApplyRemediationJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public string $serverId,
        public ?string $siteId,
        public string $code,
        public string $actionKey,
        public ?string $userId = null,
        public ?string $errorEventId = null,
    ) {
        $this->onQueue('dply-control');
    }

    /** Stream to the site when there is one (deploy/site errors), else the server. */
    protected function consoleSubject(): Model
    {
        if ($this->siteId !== null && ($site = Site::find($this->siteId)) !== null) {
            return $site;
        }

        return Server::findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'remediation_apply';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ExecuteRemoteTaskOnServer $exec, RemediationCatalog $catalog): void
    {
        $server = Server::find($this->serverId);
        $action = $catalog->action($this->code, $this->actionKey);
        $emit = $this->beginConsoleAction();

        if ($server === null || $action === null) {
            $emit->error('fix', __('That remediation is no longer available.'));
            $this->failConsoleAction(__('Remediation not found.'));

            return;
        }

        // Class-backed action (the `handler` path) — for fixes that can't be a
        // declarative script, e.g. regenerating a site's nginx vhost via a job.
        $handlerClass = is_string($action['handler'] ?? null) ? $action['handler'] : null;
        if ($handlerClass !== null
            && in_array($handlerClass, $catalog->handlerClasses(), true)
            && is_a($handlerClass, RemediationActionInterface::class, true)) {
            $emit->step('fix', sprintf('Applying “%s” …', (string) ($action['label'] ?? $this->actionKey)));
            $site = $this->siteId !== null ? Site::find($this->siteId) : null;
            $error = app($handlerClass)->apply($server, $site, $this->userId, $emit);

            if ($error === null) {
                $this->completeConsoleAction();
                $this->resolveOriginatingError();
            } else {
                $emit->error('fix', $error);
                $this->failConsoleAction($error);
            }

            return;
        }

        $script = is_string($action['script'] ?? null) ? $action['script'] : null;
        if ($script === null) {
            $emit->error('fix', __('This action isn’t runnable from here yet.'));
            $this->failConsoleAction(__('Action has no runnable script.'));

            return;
        }

        $emit->step('fix', sprintf('Applying “%s” on %s …', (string) ($action['label'] ?? $this->actionKey), $server->name));

        // The SSH layer doesn't reliably propagate the inner script's exit code
        // (see SshConnection::exec), so wrap the script in a subshell and emit an
        // explicit marker with its real status — that's the source of truth.
        $marker = 'DPLY_REMEDIATION_EXIT';
        $wrapped = "set +e\n(\n{$script}\n)\n__dply_rc=\$?\necho \"{$marker}:\${__dply_rc}\"\nexit \${__dply_rc}\n";

        try {
            $output = $exec->runInlineBashWithOutputCallback(
                $server,
                'remediation:'.$this->code.':'.$this->actionKey,
                $wrapped,
                function (string $type, string $chunk) use ($emit, $marker): void {
                    foreach (preg_split("/\r?\n/", $chunk) ?: [] as $line) {
                        $line = trim($line);
                        // Don't surface the bookkeeping marker to the operator.
                        if ($line !== '' && ! str_starts_with($line, $marker.':')) {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'fix');
                        }
                    }
                },
                timeoutSeconds: 540,
                asRoot: true,
            );

            $rc = $this->parseExitMarker((string) $output->buffer, $marker);
            if ($rc !== 0) {
                throw new \RuntimeException(
                    $rc === null
                        ? 'The fix did not run to completion.'
                        : (Str::limit($this->lastMeaningfulLine((string) $output->buffer), 400) ?: "The fix command exited {$rc}.")
                );
            }

            $emit->success('fix', __('Fix applied. Re-run the operation to continue.'));
            $this->completeConsoleAction();
            $this->resolveOriginatingError();
        } catch (\Throwable $e) {
            $emit->error('fix', Str::limit($e->getMessage(), 800));
            $this->failConsoleAction(Str::limit($e->getMessage(), 800));
        }
    }

    /** Resolve the originating error on success (Q7: fix → resolve). Shared dismissed_at. */
    private function resolveOriginatingError(): void
    {
        if ($this->errorEventId !== null) {
            ErrorEvent::query()->whereKey($this->errorEventId)->whereNull('dismissed_at')->update([
                'dismissed_at' => now(),
                'dismissed_by' => $this->userId,
            ]);
        }
    }

    /** The script's real exit status from the trailing marker, or null if absent. */
    private function parseExitMarker(string $buffer, string $marker): ?int
    {
        if (preg_match_all('/'.preg_quote($marker, '/').':(\d+)/', $buffer, $m) && $m[1] !== []) {
            return (int) end($m[1]);
        }

        return null;
    }

    /** The most useful tail line for an error message — prefer apt/script `E:`/error lines. */
    private function lastMeaningfulLine(string $buffer): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r?\n/", $buffer) ?: []), fn ($l) => $l !== ''));
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^(E:|error|fatal|could not|unable|exception)/i', $line)) {
                return $line;
            }
        }

        return (string) end($lines);
    }
}
