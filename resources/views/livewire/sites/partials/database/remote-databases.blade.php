<section class="border-b border-brand-ink/10">
    <div class="flex items-start gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
        <x-heroicon-o-cloud class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Hosted databases') }}</h3>
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-brand-moss">{{ __('Remote databases attached to this site. Configure size, credentials, and networking without installing an engine on this server.') }}</p>
        </div>
    </div>

    <ul class="divide-y divide-brand-ink/5">
        @foreach ($remoteDatabases as $remote)
            <li class="px-5 py-2.5 sm:px-6" wire:key="remote-db-{{ $remote['id'] }}">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-sm font-semibold text-brand-ink">{{ $remote['name'] }}</span>
                            <span class="rounded-md bg-brand-sand/70 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                {{ $remote['engine'] }}
                            </span>
                            <span class="rounded-md bg-brand-sand/70 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                {{ $remote['placement'] }}
                            </span>
                        </div>
                        <p class="mt-1 truncate font-mono text-xs text-brand-moss">
                            {{ $remote['host'] ?: $remote['status'] }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <livewire:sites.database-connect
                            :site="$site"
                            :server="$server"
                            :binding-id="$remote['id']"
                            :key="'db-connect-'.$remote['id']"
                        />
                        <x-secondary-button
                            size="xs"
                            href="{{ route('sites.environment', ['server' => $server, 'site' => $site]) }}"
                            wire:navigate
                        >
                            <x-heroicon-o-cog-6-tooth class="h-4 w-4" />
                            {{ __('Configure') }}
                        </x-secondary-button>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</section>
