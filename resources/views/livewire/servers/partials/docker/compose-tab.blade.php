<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Compose projects') }}</h2>
        <button type="button" wire:click="loadComposeProjects" wire:loading.attr="disabled" wire:target="loadComposeProjects" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
            <span wire:loading.remove wire:target="loadComposeProjects" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Refresh') }}
            </span>
            <span wire:loading wire:target="loadComposeProjects" class="inline-flex items-center gap-1.5">
                <x-spinner variant="forest" size="sm" />
                {{ __('Refreshing…') }}
            </span>
        </button>
    </div>

    @if ($composeLoading && $composeProjects === null)
        <div class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading compose projects…') }}
        </div>
    @elseif ($composeError)
        <p class="px-6 py-8 text-sm text-rose-700 sm:px-7">{{ $composeError }}</p>
    @elseif ($composeProjects === [] || $composeProjects === null)
        <p class="px-6 py-8 text-sm text-brand-moss sm:px-7">{{ __('No compose projects reported. Requires the Docker Compose plugin on the host.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('Project') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Config files') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
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
                            <td class="px-4 py-3 sm:px-6">
                                <div class="font-mono text-xs text-brand-ink">{{ $project }}</div>
                                @if ($linkedSite)
                                    <a href="{{ $linkedSite['url'] }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-brand-forest hover:underline">
                                        <x-heroicon-o-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('Site: :name', ['name' => $linkedSite['name']]) }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['status'] }}</td>
                            <td class="max-w-md truncate px-4 py-3 font-mono text-[11px] text-brand-moss" title="{{ $config }}">{{ $config }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex flex-wrap justify-end gap-1.5">
                                    <button type="button" wire:click="openComposeLogs(@js($project), @js($config))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Logs') }}</button>
                                    <button type="button" wire:click="confirmDockerComposeAction('docker_compose_up', @js($project), @js($config))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Up') }}</button>
                                    <button type="button" wire:click="confirmDockerComposeAction('docker_compose_restart', @js($project), @js($config))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                    <button type="button" wire:click="confirmDockerComposeAction('docker_compose_down', @js($project), @js($config))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Down') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="mt-3 text-xs text-brand-moss">
    {{ __('dply site deploys on Docker hosts write docker-compose.dply.yml under each site checkout. Up rebuilds and starts services; Down stops containers for the project.') }}
</p>
