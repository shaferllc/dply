<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use Illuminate\Support\Carbon;

/**
 * Builds the array shape rendered by the _container-launch-progress.blade.php
 * partial. Shared between WorkspaceOverview (Docker hosts) and WorkspaceCluster
 * (K8s hosts) so a container-site launch surfaces progress in whichever main
 * destination the user is on.
 *
 * Requires the consuming component to expose a public Server $server property.
 */
trait BuildsContainerLaunchSummary
{
    /**
     * @return array<string, mixed>|null
     */
    protected function containerLaunchSummary(): ?array
    {
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $launch = is_array($meta['container_launch'] ?? null) ? $meta['container_launch'] : [];
        if ($launch === []) {
            return null;
        }

        $status = (string) ($launch['status'] ?? '');
        if ($status === '' || $status === 'completed') {
            return null;
        }

        $siteId = is_string($launch['site_id'] ?? null) ? $launch['site_id'] : null;
        $site = $siteId ? $this->server->sites()->with('domains')->find($siteId) : null;
        $events = collect(is_array($launch['events'] ?? null) ? $launch['events'] : [])
            ->filter(fn (mixed $event): bool => is_array($event) && is_string($event['message'] ?? null))
            ->take(-5)
            ->values()
            ->all();

        return [
            'status' => $status,
            'target_family' => (string) ($launch['target_family'] ?? 'container'),
            'repository_url' => (string) ($launch['repository_url'] ?? ''),
            'repository_branch' => (string) ($launch['repository_branch'] ?? ''),
            'repository_subdirectory' => (string) ($launch['repository_subdirectory'] ?? ''),
            'current_step_label' => (string) ($launch['current_step_label'] ?? 'Container launch in progress'),
            'summary' => (string) ($launch['summary'] ?? 'Dply is still preparing this container launch.'),
            'updated_at' => isset($launch['updated_at']) ? Carbon::parse((string) $launch['updated_at']) : null,
            'events' => $events,
            'site' => $site,
            'site_route' => $site ? route('sites.show', ['server' => $this->server, 'site' => $site]) : null,
            'steps' => $this->containerLaunchSteps($status),
            'is_failed' => $status === 'failed',
        ];
    }

    /**
     * Step list for the in-flight container launch, mirroring the status
     * progression in FinalizeContainerCloudLaunchJob::updateLaunchState.
     *
     * @return list<array{key: string, label: string, state: string}>
     */
    private function containerLaunchSteps(string $status): array
    {
        $steps = [
            ['key' => 'waiting_for_server', 'label' => __('Provisioning server')],
            ['key' => 'creating_site', 'label' => __('Creating site record')],
            ['key' => 'waiting_for_site_provisioning', 'label' => __('Provisioning site workspace')],
            ['key' => 'ready', 'label' => __('Site ready for first deploy')],
        ];

        $order = ['waiting_for_server', 'creating_site', 'waiting_for_site_provisioning', 'ready'];
        $currentIdx = array_search($status, $order, true);

        return array_map(function (array $step, int $idx) use ($status, $currentIdx): array {
            $state = 'pending';
            if ($status === 'failed') {
                $state = 'pending';
            } elseif ($currentIdx === false) {
                $state = 'pending';
            } elseif ($idx < $currentIdx) {
                $state = 'completed';
            } elseif ($idx === $currentIdx) {
                $state = 'active';
            }

            return ['key' => $step['key'], 'label' => $step['label'], 'state' => $state];
        }, $steps, array_keys($steps));
    }

    /**
     * Formats container_launch events into a human-readable transcript string
     * for the "Recent events" pre block in the launch progress banner.
     *
     * @param  array<string, mixed>|null  $containerLaunch
     */
    protected function containerLaunchTranscript(?array $containerLaunch): string
    {
        return collect($containerLaunch['events'] ?? [])->map(function (array $event): string {
            $timestamp = (string) ($event['at'] ?? '');
            $level = strtoupper((string) ($event['level'] ?? 'info'));
            $message = (string) ($event['message'] ?? 'Container launch update');
            $lines = [];

            $prefixParts = array_values(array_filter([$timestamp, $level]));
            $lines[] = ($prefixParts !== [] ? '['.implode('] [', $prefixParts).'] ' : '').$message;

            foreach (collect($event['context'] ?? [])->filter(fn ($value) => ! is_array($value)) as $contextKey => $contextValue) {
                $rendered = is_bool($contextValue) ? ($contextValue ? 'true' : 'false') : (string) $contextValue;
                if ($rendered === '') {
                    continue;
                }
                $lines[] = '  > '.str_replace('_', ' ', (string) $contextKey).': '.$rendered;
            }

            return implode("\n", $lines);
        })->implode("\n\n");
    }
}
