<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\WebserverConfigDriftDetector;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesWebserverDriftSmoke
{
    // ---- Site smoke-test results (Overview tab card).
    /**
     * Per-site smoke test result entries from {@see WebserverSmokeTestRunner}.
     *
     * @var list<array<string, mixed>>
     */
    public array $smoke_results = [];

    public ?string $smoke_scanned_at_iso = null;

    public int $smoke_total_sites = 0;

    public int $smoke_probed = 0;

    public bool $smoke_truncated = false;

    public bool $smoke_loaded = false;

    public ?string $smoke_error = null;

    // ---- Config drift detector (Overview tab card).
    /** @var list<array<string, mixed>> */
    public array $drift_results = [];

    public ?string $drift_engine = null;

    public ?string $drift_scanned_at_iso = null;

    public int $drift_total_sites = 0;

    public int $drift_count = 0;

    public bool $drift_truncated = false;

    public bool $drift_unsupported = false;

    public bool $drift_loaded = false;

    public ?string $drift_error = null;

    /**
     * Run the smoke test for every Site on this server through the active
     * webserver via localhost. Banner-streamed because this can take a few
     * seconds on servers with lots of sites — queueing via a job would be
     * overkill here since each curl is capped at 4s and the total is
     * bounded.
     */
    public function runSmokeTest(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->smoke_error = __('Provisioning and SSH must be ready before running the smoke test.');

            return;
        }

        $this->smoke_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Site smoke test'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $result = app(\App\Services\Servers\WebserverSmokeTestRunner::class)->run($this->server, $emitter);
            // Serialize Carbon to ISO string for Livewire state.
            $this->smoke_results = array_map(function (array $row): array {
                return $row;
            }, $result['results']);
            $this->smoke_scanned_at_iso = $result['scanned_at']->toIso8601String();
            $this->smoke_total_sites = (int) $result['total_sites'];
            $this->smoke_probed = (int) $result['probed'];
            $this->smoke_truncated = (bool) $result['truncated'];
            $this->smoke_loaded = true;

            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->smoke_error = $e->getMessage();
        }
    }

    public function loadDriftDetector(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->drift_error = __('Provisioning and SSH must be ready before checking config drift.');

            return;
        }

        try {
            $result = app(WebserverConfigDriftDetector::class)->detect($this->server, $forceFresh);
            $this->drift_results = $result['results'];
            $this->drift_engine = $result['engine'];
            $this->drift_scanned_at_iso = $result['scanned_at']->toIso8601String();
            $this->drift_total_sites = (int) $result['total_sites'];
            $this->drift_count = (int) $result['drifted_count'];
            $this->drift_truncated = (bool) $result['truncated'];
            $this->drift_unsupported = (bool) $result['unsupported'];
            $this->drift_loaded = true;
            $this->drift_error = null;
        } catch (\Throwable $e) {
            $this->drift_error = __('Failed to detect drift: :msg', ['msg' => $e->getMessage()]);
            $this->drift_loaded = false;
        }
    }

    public function refreshDriftDetector(): void
    {
        $this->loadDriftDetector(forceFresh: true);
    }
}
