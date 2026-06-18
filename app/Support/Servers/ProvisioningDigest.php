<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Throwable;

/**
 * Compact "what's happening on this server right now" digest for the fleet
 * list. The full journey page does the heavy lifting (per-step state, ETAs,
 * artifacts) — this is the squeezed-down version that fits on a single line
 * under the server name on /servers.
 *
 * Only relevant for in-progress servers; returns null for fully-ready or
 * non-VM hosts (K8s clusters surface their own provisioning state via the
 * cluster page, not this list).
 */
final class ProvisioningDigest
{
    /**
     * @param  string  $phaseLabel  e.g. "Cloud provisioning" / "Server setup"
     * @param  string  $stepLabel  e.g. "Waiting for SSH" / "Installing system updates"
     * @param  ?int  $stepIndex  1-based position within the current phase (null when unknown)
     * @param  ?int  $stepTotal  total step count for the current phase (null when unknown)
     */
    private function __construct(
        public string $phaseLabel,
        public string $stepLabel,
        public ?int $stepIndex,
        public ?int $stepTotal,
        public ?int $elapsedSeconds,
    ) {}

    public static function forServer(Server $server): ?self
    {
        // Don't show a digest for K8s hosts here — their lifecycle is
        // surfaced on the dedicated cluster page, not the VM journey.
        if (! $server->isVmHost()) {
            return null;
        }

        // Fully-ready VM hosts get the normal "Online for X days" line, no
        // digest needed.
        if ($server->status === Server::STATUS_READY
            && $server->setup_status === Server::SETUP_STATUS_DONE) {
            return null;
        }

        // Error / disconnected servers have their own error chips; the
        // digest is only for in-flight provisioning.
        if (in_array($server->status, [Server::STATUS_ERROR, Server::STATUS_DISCONNECTED], true)) {
            return null;
        }

        $setupRunning = $server->status === Server::STATUS_READY
            && $server->setup_status === Server::SETUP_STATUS_RUNNING;
        $setupFailed = $server->setup_status === Server::SETUP_STATUS_FAILED;

        try {
            $elapsedSeconds = max(0, (int) $server->created_at->diffInSeconds(now()));
        } catch (Throwable) {
            $elapsedSeconds = null;
        }

        // Setup phase — derive the current scripted step from the most-recently
        // updated provision_step_snapshots entry. TaskRunnerTaskObserver writes
        // these whenever the bash script emits a [dply-step] marker, so the
        // latest entry's label is the operator's "what is the script doing now"
        // signal.
        if ($setupRunning || $setupFailed) {
            $meta = is_array($server->meta) ? $server->meta : [];
            $snapshots = is_array($meta['provision_step_snapshots'] ?? null)
                ? $meta['provision_step_snapshots']
                : [];

            $entries = collect($snapshots)
                ->filter(static fn ($snap): bool => is_array($snap) && isset($snap['label']))
                ->values();
            $total = $entries->count();
            $emitted = $entries->filter(static fn (array $snap): bool => isset($snap['updated_at']))->values();
            $latest = $emitted->sortByDesc(static fn (array $snap): string => (string) $snap['updated_at'])->first();

            if (is_array($latest)) {
                $stepLabel = (string) $latest['label'];
                $index = (int) $emitted->count();

                return new self(
                    phaseLabel: __('Server setup'),
                    stepLabel: $stepLabel,
                    stepIndex: $index,
                    stepTotal: $total > 0 ? $total : null,
                    elapsedSeconds: $elapsedSeconds,
                );
            }

            return new self(
                phaseLabel: __('Server setup'),
                stepLabel: __('Running setup script'),
                stepIndex: null,
                stepTotal: null,
                elapsedSeconds: $elapsedSeconds,
            );
        }

        // Cloud phase — the journey calls these "queued / provisioning / ip /
        // ssh / ready", but the cloud phase is only 4 actionable waits before
        // setup takes over. We derive the verb from status + ip_address +
        // setup_status, matching the journey's activeKey logic.
        [$stepLabel, $stepIndex] = match (true) {
            $server->status === Server::STATUS_PENDING => [__('Request queued with provider'), 1],
            $server->status === Server::STATUS_PROVISIONING && empty($server->ip_address) => [__('Provisioning server'), 2],
            $server->status === Server::STATUS_PROVISIONING => [__('Waiting for SSH'), 3],
            $server->status === Server::STATUS_READY
                && $server->setup_status === Server::SETUP_STATUS_PENDING => [__('Waiting for SSH'), 3],
            $server->status === Server::STATUS_READY => [__('Waiting for setup to start'), 4],
            default => [__('Provisioning'), null],
        };

        return new self(
            phaseLabel: __('Cloud provisioning'),
            stepLabel: $stepLabel,
            stepIndex: $stepIndex,
            stepTotal: 4,
            elapsedSeconds: $elapsedSeconds,
        );
    }

    /**
     * Short human-readable form of elapsedSeconds: "12s", "3m", "1h 12m".
     * Caps at hours since anything longer is a stall the operator should
     * have noticed via the journey page already.
     */
    public function elapsedHuman(): ?string
    {
        if ($this->elapsedSeconds === null) {
            return null;
        }
        $s = $this->elapsedSeconds;
        if ($s < 60) {
            return $s.'s';
        }
        if ($s < 3600) {
            return intdiv($s, 60).'m';
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
