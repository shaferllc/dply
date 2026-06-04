<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
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
        public string $siteId,
        public string $code,
        public string $actionKey,
        public ?string $userId = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
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
        $server = Server::query()->find($this->serverId);
        $action = $catalog->action($this->code, $this->actionKey);
        $emit = $this->beginConsoleAction();

        if ($server === null || $action === null) {
            $emit->error('fix', __('That remediation is no longer available.'));
            $this->failConsoleAction(__('Remediation not found.'));

            return;
        }

        $script = is_string($action['script'] ?? null) ? $action['script'] : null;
        if ($script === null) {
            $emit->error('fix', __('This action isn’t runnable from here yet.'));
            $this->failConsoleAction(__('Action has no runnable script.'));

            return;
        }

        $emit->step('fix', sprintf('Applying “%s” on %s …', (string) ($action['label'] ?? $this->actionKey), $server->name));

        try {
            $output = $exec->runInlineBashWithOutputCallback(
                $server,
                'remediation:'.$this->code.':'.$this->actionKey,
                $script,
                function (string $type, string $chunk) use ($emit): void {
                    foreach (preg_split("/\r?\n/", $chunk) ?: [] as $line) {
                        if (trim($line) !== '') {
                            $emit(trim($line), ConsoleAction::LEVEL_INFO, 'fix');
                        }
                    }
                },
                timeoutSeconds: 540,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(Str::limit(trim($output->buffer), 800) ?: 'The fix command failed.');
            }

            $emit->success('fix', __('Fix applied. Re-run the deployment to continue.'));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error('fix', Str::limit($e->getMessage(), 800));
            $this->failConsoleAction(Str::limit($e->getMessage(), 800));
        }
    }
}
