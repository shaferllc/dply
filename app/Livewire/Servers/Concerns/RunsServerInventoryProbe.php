<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerInventoryProbeScript;
use App\Services\SshConnection;
use Illuminate\Support\Facades\DB;

/**
 * Refresh the server's inventory + manage probe over SSH. Streams output to two
 * surfaces in parallel:
 *
 *   - The legacy `StreamsRemoteSshLivewire` panel (the live tail under the
 *     button on Settings → Inventory and Manage → Overview).
 *   - An `inventory_probe` ConsoleAction row that the hoisted Manage banner
 *     renders alongside install / restart actions. Lets every Manage caller
 *     share the same console surface without ripping out the legacy panel.
 *
 * Composed by both WorkspaceSettings (via ManagesWorkspaceSettingsForm) and
 * WorkspaceManage. The ConsoleAction emit is best-effort — if the model write
 * fails (test environment, transient DB hiccup) the legacy panel still works.
 */
trait RunsServerInventoryProbe
{
    use StreamsRemoteSshLivewire;

    public function refreshServerInventoryDetails(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunInventoryProbe()) {
            $this->toastError(__('Deployers cannot run server inventory over SSH.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Server must be ready with SSH before refreshing inventory.'));

            return;
        }

        $script = $this->buildInventoryShellScript();
        $timeout = $this->inventorySshTimeoutSeconds();

        $wrapped = '/bin/sh -c '.escapeshellarg($script);
        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $wantRoot = (bool) config('server_settings.inventory_use_root_ssh', true);
        $fallback = (bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true);
        $candidates = [];
        if ($wantRoot && $deploy !== 'root') {
            $candidates[] = 'root';
            if ($fallback) {
                $candidates[] = $deploy;
            }
        } else {
            $candidates[] = $deploy;
        }

        $this->resetRemoteSshStreamTargets();

        // Seed a ConsoleAction row so the Manage banner picks the run up
        // immediately. The probe runs synchronously below; the row's output
        // grows as chunks arrive, and we flip status at the end. Failure to
        // seed (auth quirks in tests, etc.) is non-fatal — we just don't
        // get the banner surface for this run.
        $consoleRow = $this->seedInventoryProbeConsoleAction();
        $emitter = $consoleRow !== null ? new ConsoleEmitter((string) $consoleRow->id) : null;
        if ($consoleRow !== null) {
            DB::table('console_actions')->where('id', $consoleRow->id)->update([
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($emitter !== null) {
            $emitter->step('dply', __('Connecting over SSH …'));
        }

        $lastError = null;
        $out = null;

        foreach ($candidates as $i => $loginUser) {
            $this->remoteSshStreamSetMeta(
                __('Refresh inventory'),
                sprintf('%s@%s  %s', $loginUser, $this->server->ip_address, $wrapped)
            );
            if ($i > 0) {
                $this->remoteSshStreamAppendStdout("\n\n--- ".__('Retrying as deploy SSH user')." ---\n\n");
                if ($emitter !== null) {
                    $emitter->warn(__('Retrying as deploy SSH user (:user) …', ['user' => $loginUser]), 'dply');
                }
            } elseif ($emitter !== null) {
                $emitter('ssh '.$loginUser.'@'.$this->server->ip_address, ConsoleAction::LEVEL_INFO, 'dply');
            }

            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $buffer = '';
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    function (string $chunk) use (&$buffer, $emitter): void {
                        $this->remoteSshStreamAppendStdout($chunk);
                        // Flush whole lines into the ConsoleAction so the banner
                        // shows incrementally. Keep any trailing partial line in
                        // $buffer until the next chunk or the final flush below.
                        if ($emitter === null) {
                            return;
                        }
                        $buffer .= $chunk;
                        while (($pos = strpos($buffer, "\n")) !== false) {
                            $line = rtrim(substr($buffer, 0, $pos), "\r");
                            $buffer = substr($buffer, $pos + 1);
                            if ($line !== '') {
                                $emitter($line);
                            }
                        }
                    },
                    $timeout,
                ));
                if ($emitter !== null && $buffer !== '') {
                    $emitter(rtrim($buffer, "\r"));
                }
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $message = $lastError !== null ? $lastError->getMessage() : __('SSH connection failed for inventory check.');
            if ($consoleRow !== null) {
                $this->finalizeInventoryProbeConsoleAction($consoleRow, success: false, errorMessage: $message);
            }
            $this->toastError($message);

            return;
        }

        try {
            $maxPreviewBytes = max(1024, (int) config('server_settings.inventory_package_preview_max_bytes', 16384));
            $maxExtBytes = (int) config('server_settings.inventory_extended_max_bytes', 32000);

            $meta = app(ServerInventoryProbeScript::class)->parse(
                $out,
                $this->server->meta ?? [],
                $maxPreviewBytes,
                $maxExtBytes,
            );

            $this->server->update(['meta' => $meta]);
            $this->server->refresh();

            if (method_exists($this, 'syncSettingsFormFromServer')) {
                $this->syncSettingsFormFromServer();
            }
            if (method_exists($this, 'syncExtendedServerSettingsFromServer')) {
                $this->syncExtendedServerSettingsFromServer();
            }

            if ($consoleRow !== null) {
                if ($emitter !== null) {
                    $emitter->success(__('Inventory parsed and persisted.'), 'dply');
                }
                $this->finalizeInventoryProbeConsoleAction($consoleRow, success: true);
            }

            $this->toastSuccess(__('Server inventory refreshed from SSH.'));
        } catch (\Throwable $e) {
            if ($consoleRow !== null) {
                $this->finalizeInventoryProbeConsoleAction($consoleRow, success: false, errorMessage: $e->getMessage());
            }
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Seed an `inventory_probe` ConsoleAction for the in-flight probe. Auto-dismisses
     * any prior terminal probe rows for this server so the banner-getter picks the
     * one we just created. Returns null on failure (e.g. test setup without a writable
     * DB) — callers treat the row as optional.
     */
    protected function seedInventoryProbeConsoleAction(): ?ConsoleAction
    {
        try {
            $subjectType = $this->server->getMorphClass();
            $subjectId = $this->server->id;

            ConsoleAction::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('kind', 'inventory_probe')
                ->whereNull('dismissed_at')
                ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
                ->update(['dismissed_at' => now()]);

            return ConsoleAction::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'kind' => 'inventory_probe',
                'status' => ConsoleAction::STATUS_QUEUED,
                'user_id' => auth()->id(),
                'label' => __('Refreshing inventory …'),
                'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function finalizeInventoryProbeConsoleAction(ConsoleAction $row, bool $success, ?string $errorMessage = null): void
    {
        try {
            DB::table('console_actions')->where('id', $row->id)->update([
                'status' => $success ? ConsoleAction::STATUS_COMPLETED : ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => $errorMessage !== null ? mb_substr($errorMessage, 0, 2000) : null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Swallow — best-effort console emit; never block the probe outcome.
        }
    }

    protected function buildInventoryShellScript(): string
    {
        $previewLines = (int) config('server_settings.inventory_package_preview_lines', 80);
        $depth = (string) (($this->server->fresh()->meta ?? [])['inventory_scan_depth'] ?? 'basic');

        // Manage callers always want the extended snapshot regardless of the user's depth preference.
        $forceExtended = $this->forceExtendedInventoryProbe();

        // Pass the deploy user through so the probe can sudo into them for
        // per-user toolchain blocks (mise stores runtime data under
        // ~deploy/.local/share/mise; root's mise is empty). Falls back to
        // the configured platform default when the server doesn't override it.
        $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
            ? (string) $this->server->ssh_user
            : (string) config('server_provision.deploy_ssh_user', 'dply');

        return app(ServerInventoryProbeScript::class)->build(
            extended: $forceExtended || $depth === 'extended',
            previewLines: $previewLines,
            deployUser: $deployUser !== 'root' ? $deployUser : null,
        );
    }

    protected function inventorySshTimeoutSeconds(): int
    {
        $depth = (string) (($this->server->fresh()->meta ?? [])['inventory_scan_depth'] ?? 'basic');
        $extended = $this->forceExtendedInventoryProbe() || $depth === 'extended';

        return $extended
            ? (int) config('server_settings.inventory_ssh_timeout_extended', 180)
            : (int) config('server_settings.inventory_ssh_timeout_basic', 120);
    }

    /**
     * Subclasses can override to force the extended snapshot (Manage tabs always want it).
     */
    protected function forceExtendedInventoryProbe(): bool
    {
        return false;
    }

    protected function canRunInventoryProbe(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }
}
