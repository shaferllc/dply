<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use App\Support\Sites\BindingReachability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Validates that every networked resource binding on a site is reachable from
 * the site's server, in one pass. Reachability has to be tested from the box
 * that dials each resource at runtime, so this opens a single SSH session and
 * runs a pure-bash TCP probe against each binding's host:port (resolved by
 * {@see BindingReachability}). Each result is recorded on the binding
 * (config.connectivity + last_error) so the Resources map can badge it, and a
 * line per binding streams into the page-top console banner.
 *
 * TCP-level only — it answers "can this server open a socket to the service?",
 * which is the cross-network reachability concern. Auth/protocol checks are a
 * future refinement. Mirrors {@see ValidateBindingConnectivityJob} (single
 * binding) but batched for the "Validate reachability" button.
 */
class ValidateSiteBindingsReachableJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
    ) {}

    public function handle(SshConnectionFactory $factory): void
    {
        $site = Site::query()->with(['server', 'bindings'])->find($this->siteId);
        $action = ConsoleAction::query()->find($this->consoleActionId);

        if ($site === null || $action === null || $site->server === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);
        $conn = null;

        // Resolve every networked binding to a host:port up front.
        $targets = [];
        foreach ($site->bindings as $binding) {
            $target = BindingReachability::target($binding);
            if ($target !== null) {
                $targets[] = [$binding, $target['host'], $target['port']];
            }
        }

        if ($targets === []) {
            $emit->info('No networked resources to check — nothing carries a host:port.');
            $this->finishRun($emit, false);

            return;
        }

        try {
            $emit->step('probe', sprintf('From %s — checking %d resource(s) …', (string) $site->server->name, count($targets)));

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(10)) {
                throw new \RuntimeException('Could not open SSH to '.$site->server->name.'.');
            }

            $failures = 0;
            foreach ($targets as [$binding, $host, $port]) {
                $label = $binding->name ?: $binding->type;

                // Pure-bash TCP probe: no client binary needed, works for any engine.
                $cmd = sprintf(
                    "timeout 5 bash -c '</dev/tcp/%s/%d' >/dev/null 2>&1 && echo DPLY_PROBE_OK || echo DPLY_PROBE_FAIL",
                    $host,
                    $port,
                );
                $out = (string) $conn->exec($cmd, 15);
                $reachable = str_contains($out, 'DPLY_PROBE_OK');

                if ($reachable) {
                    $emit->success('probe', sprintf('%s — reachable at %s:%d.', $label, $host, $port));
                    $this->record($binding, true, null, $host, $port);
                } else {
                    $failures++;
                    $detail = sprintf('%s could not reach %s:%d.', (string) $site->server->name, $host, $port);
                    $emit->warn(sprintf('%s — unreachable at %s:%d.', $label, $host, $port), 'probe');
                    $this->record($binding, false, $detail, $host, $port);
                }
            }

            if ($failures > 0) {
                $emit->step('probe', 'Unreachable services usually aren\'t listening on that address/port, aren\'t bound to the private interface, are firewalled in-host, or don\'t allow this server\'s private IP.');
            }

            $emit->success('probe', sprintf('Done — %d reachable, %d unreachable.', count($targets) - $failures, $failures));
            $this->finishRun($emit, false);
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $emit->error('Reachability check failed: '.$message, 'probe');
            $this->finishRun($emit, true, $message);
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }

    private function record(SiteBinding $binding, bool $ok, ?string $error, string $host, int $port): void
    {
        $config = $binding->config;
        $config['connectivity'] = [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'detail' => $error,
            'host' => $host,
            'port' => $port,
        ];
        $binding->forceFill([
            'config' => $config,
            'last_error' => $ok ? null : $error,
        ])->save();
    }

    private function finishRun(ConsoleEmitter $emit, bool $failed, ?string $error = null): void
    {
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $failed ? ConsoleAction::STATUS_FAILED : ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => $failed ? $error : null,
            'updated_at' => now(),
        ]);
    }
}
