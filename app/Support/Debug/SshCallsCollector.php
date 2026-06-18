<?php

declare(strict_types=1);

namespace App\Support\Debug;

use App\Services\SshConnection;
use DebugBar\DataCollector\Renderable;
use DebugBar\DataCollector\TimeDataCollector;
use Illuminate\Support\Str;

/**
 * Debugbar collector that surfaces every inline SSH call made on the current
 * page request as timeline measures (one bar per command, sized by duration).
 *
 * NOTE: dply queues nearly all SSH into jobs (PHP's 30s request ceiling), so
 * those calls run in a separate worker process and will NOT appear here. This
 * tab captures the *inline* SSH a page does itself — e.g. reading a webserver
 * config file over a direct phpseclib channel. The persistent job-side audit
 * lives in the `server_remote_access_events` table.
 *
 * Reads from {@see SshCallRecorder} at collect() time, keeping
 * {@see SshConnection} free of any Debugbar dependency.
 */
final class SshCallsCollector extends TimeDataCollector implements Renderable
{
    public function __construct(
        private readonly SshCallRecorder $recorder,
        ?float $requestStartTime = null,
    ) {
        parent::__construct($requestStartTime);
    }

    public function collect(): array
    {
        foreach ($this->recorder->all() as $call) {
            $status = $call['error'] !== null
                ? 'ERR'
                : ($call['exit_code'] === null ? '' : 'exit '.$call['exit_code']);

            $label = trim(sprintf(
                '%s %s%s',
                $call['type'],
                Str::limit(Str::squish($call['command']), 90),
                $status !== '' ? '  ['.$status.']' : '',
            ));

            $this->addMeasure(
                $label,
                $call['started_at'],
                $call['ended_at'],
                array_filter([
                    'server' => trim($call['server_name'].' ('.$call['host'].')'),
                    'user' => $call['user'],
                    'role' => $call['role'],
                    'type' => $call['type'],
                    'command' => $call['command'],
                    'exit_code' => $call['exit_code'],
                    'bytes_out' => $call['bytes_out'],
                    'error' => $call['error'],
                ], static fn ($value): bool => $value !== null && $value !== ''),
            );
        }

        $data = parent::collect();
        $data['nb_measures'] = count($data['measures']);

        // TimeDataCollector sets count=0 when there are no measures, which makes
        // Debugbar mark the tab data-empty and hide it under hide_empty_tabs
        // (on by default) — yet the indicator stays visible, so the tab would
        // flicker in/out per request. Drop count so the SSH tab is always shown
        // (the badge still reflects nb_measures); the timeline reads measures.
        unset($data['count']);

        $durations = array_column($data['measures'], 'duration');
        $slowest = $durations === [] ? 0.0 : max($durations);
        $total = array_sum($durations);

        $formatter = $this->getDataFormatter();
        $data['ssh_total_str'] = $formatter->formatDuration($total);
        $data['ssh_slowest_str'] = $formatter->formatDuration($slowest);

        // Main-row indicator: "<slowest> / <total>" so the headline cost of a
        // page's inline SSH is visible without opening the tab (tooltip can't be
        // data-mapped, so both numbers live in the value). "—" when none.
        $data['indicator'] = $data['nb_measures'] === 0
            ? '—'
            : sprintf('%s / %s', $data['ssh_slowest_str'], $data['ssh_total_str']);

        return $data;
    }

    public function getName(): string
    {
        return 'ssh';
    }

    public function getWidgets(): array
    {
        return [
            // Always-visible main-row indicator: slowest / total inline SSH time.
            // Clicking it opens the SSH tab.
            'ssh:indicator' => [
                'icon' => 'server-cog',
                'tooltip' => 'Inline SSH — slowest call / total time this request (most SSH is queued and not shown here)',
                'map' => 'ssh.indicator',
                'link' => 'ssh',
                'default' => "'—'",
            ],
            'ssh' => [
                'icon' => 'server-cog',
                'widget' => 'PhpDebugBar.Widgets.TimelineWidget',
                'map' => 'ssh',
                'default' => '{}',
            ],
            'ssh:badge' => [
                'map' => 'ssh.nb_measures',
                'default' => 0,
            ],
        ];
    }
}
