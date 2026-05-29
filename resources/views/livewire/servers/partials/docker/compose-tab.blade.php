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
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($composeProjects as $row)
                        <tr wire:key="docker-compose-{{ $row['name'] }}">
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['status'] }}</td>
                            <td class="max-w-md truncate px-4 py-3 font-mono text-[11px] text-brand-moss" title="{{ $row['config'] }}">{{ $row['config'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="mt-3 text-xs text-brand-moss">{{ __('dply site deploys using Compose live under each site workspace. This tab lists all compose projects the Docker CLI knows about on the host.') }}</p>
