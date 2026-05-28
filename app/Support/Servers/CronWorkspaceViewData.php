<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceCron;
use App\Models\Server;

/**
 * View-model for the server Cron workspace blade tree. Keeps banner/setup
 * and tab preamble logic out of {@see resources/views/livewire/servers/workspace-cron.blade.php}.
 */
final class CronWorkspaceViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        WorkspaceCron $component,
        bool $includeBannerContext = false,
        bool $includeSummaryContext = false,
    ): array {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ssh_private_key;
        $presets = [
            'every_minute' => [__('Every minute'), '* * * * *'],
            'hourly' => [__('Hourly'), '0 * * * *'],
            'nightly' => [__('Nightly (2:00)'), '0 2 * * *'],
            'weekly' => [__('Weekly (Sun 2:00)'), '0 2 * * 0'],
            'monthly' => [__('Monthly (1st 2:00)'), '0 2 1 * *'],
            'custom' => [__('Custom'), ''],
        ];

        $data = compact(
            'card',
            'opsReady',
            'presets',
        );

        if ($includeSummaryContext) {
            // On the per-site cron page (mounted with a context site and the
            // "This site only" scope) the at-a-glance counts must reflect that
            // site's jobs, not every job on the server. Switching the scope
            // toggle to "All jobs on server" widens both the list and these
            // counts back to the full server set.
            $cronSummaryScopedToSite = $component->context_site_id !== null
                && $component->cron_list_scope === 'site';

            $summaryJobs = $cronSummaryScopedToSite
                ? $server->cronJobs->where('site_id', $component->context_site_id)
                : $server->cronJobs;

            $cronJobCount = $summaryJobs->count();
            $enabledCronJobCount = $summaryJobs->where('enabled', true)->count();
            $disabledCronJobCount = $cronJobCount - $enabledCronJobCount;
            $unsyncedCronCount = $summaryJobs->where('is_synced', false)->count();
            $latestCronSync = $summaryJobs->where('synced_at')->max('synced_at');

            $data = array_merge($data, compact(
                'cronSummaryScopedToSite',
                'cronJobCount',
                'enabledCronJobCount',
                'disabledCronJobCount',
                'unsyncedCronCount',
                'latestCronSync',
            ));
        }

        if ($includeBannerContext) {
            $cronPanelBusy = $component->panel_event_status === 'running' || $component->cron_run_id !== null;
            $cronPanelSubtitle = match (true) {
                $component->cron_run_id !== null => __('Output streams here as the worker writes it.'),
                $component->panel_event_status === 'completed'
                    && count($component->panel_event_lines) > 0
                    && ! empty($component->cron_run_output) => null,
                $component->panel_event_status === 'failed' => null,
                default => __('The panel was updated. Sync the crontab to install the changes on the server.'),
            };

            $data = array_merge($data, compact(
                'cronPanelBusy',
                'cronPanelSubtitle',
            ));
        }

        return $data;
    }
}
