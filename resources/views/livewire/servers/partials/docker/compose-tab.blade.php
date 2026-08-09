<div class="border-b border-brand-ink/10">
    @include('livewire.servers.partials.docker._list-head', [
        'icon' => 'heroicon-o-rectangle-stack',
        'title' => __('Compose projects'),
        'target' => 'loadComposeProjects',
        'rows' => $composeProjects,
        'note' => __('Projects reported by docker compose ls on this host. dply site deploys write docker-compose.dply.yml under each site checkout; Up rebuilds and starts services, Down stops the project\'s containers.'),
    ])

    @if ($composeLoading || $composeError || $composeProjects === [] || $composeProjects === null)
        @include('livewire.servers.partials.docker._list-state', [
            'loading' => $composeLoading,
            'error' => $composeError,
            'rows' => $composeProjects,
            'icon' => 'heroicon-o-rectangle-stack',
            'errorTitle' => __('Could not list compose projects'),
            'emptyTitle' => __('No compose projects'),
            'emptyDescription' => __('Nothing returned from docker compose ls. This needs the Docker Compose plugin installed on the host.'),
            'columns' => [26, 16, 20, 14],
        ])
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-5">{{ __('Project') }}</th>
                        <th class="px-3 py-2">{{ __('Status') }}</th>
                        <th class="px-3 py-2">{{ __('Config files') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($composeProjects as $row)
                        @php
                            $project = $row['name'];
                            $config = $row['config'];
                            $linkedSite = \App\Support\Servers\DockerManagedSiteIndex::siteForComposeProject($row, $managedSites);
                        @endphp
                        <tr wire:key="docker-compose-{{ $project }}">
                            <td class="px-3 py-2 sm:px-5">
                                <div class="font-mono text-xs text-brand-ink">{{ $project }}</div>
                                @if ($linkedSite)
                                    <a href="{{ $linkedSite['url'] }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline">
                                        <x-heroicon-o-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('Site: :name', ['name' => $linkedSite['name']]) }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['status'] }}</td>
                            <td class="max-w-md truncate px-4 py-3 font-mono text-xs text-brand-moss" title="{{ $config }}">{{ $config }}</td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex flex-wrap justify-end gap-1.5">
                                    <button type="button" wire:click="openComposeLogs(@js($project), @js($config))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Logs') }}</button>
                                    <button type="button" wire:click="confirmDockerComposeAction('docker_compose_up', @js($project), @js($config))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Up') }}</button>
                                    <button type="button" wire:click="confirmDockerComposeAction('docker_compose_restart', @js($project), @js($config))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                    <button type="button" wire:click="confirmDockerComposeAction('docker_compose_down', @js($project), @js($config))" class="inline-flex h-6 items-center rounded-md border border-rose-200 bg-rose-50 px-2 text-xs font-semibold text-rose-800 transition hover:bg-rose-100">{{ __('Down') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
