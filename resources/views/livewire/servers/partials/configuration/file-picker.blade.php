<div class="rounded-xl border border-brand-ink/10 bg-white">
    <div class="border-b border-brand-ink/10 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Files') }}</div>

    @if ($configCatalogLoading || ($opsReady && ! $configCatalogLoaded))
        <div class="flex items-center gap-2 px-3 py-6 text-sm text-brand-moss">
            <x-spinner variant="forest" class="h-4 w-4 shrink-0" />
            <span>{{ __('Discovering config files on server…') }}</span>
        </div>
    @elseif ($configCatalogError)
        <p class="px-3 py-3 text-xs text-red-700">{{ $configCatalogError }}</p>
    @elseif (empty($groupedConfigFiles))
        <p class="px-3 py-3 text-xs text-brand-moss">{{ __('No config files discovered. Confirm the server is reachable.') }}</p>
    @else
        <div class="max-h-[60vh] overflow-auto">
            @foreach ($groupedConfigFiles as $groupKey => $group)
                <div class="border-b border-brand-ink/5 last:border-b-0">
                    <div class="sticky top-0 z-10 bg-brand-sand/80 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss backdrop-blur-sm">
                        {{ $group['label'] }}
                    </div>
                    <ul class="divide-y divide-brand-ink/5 text-sm">
                        @foreach ($group['files'] as $f)
                            @php
                                $isSel = $config_selected_path === $f['path'];
                                $isLoading = $pending_load_path === $f['path'];
                            @endphp
                            <li>
                                <button
                                    type="button"
                                    wire:click="loadConfigFile(@js($f['path']))"
                                    wire:target="loadConfigFile"
                                    wire:loading.attr="disabled"
                                    data-skip-busy="1"
                                    @class([
                                        'flex w-full items-start gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/40',
                                        'bg-brand-sand/50' => $isSel && ! $isLoading,
                                        'bg-brand-sand/60 cursor-progress' => $isLoading,
                                    ])
                                >
                                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center">
                                        @if ($isLoading)
                                            <x-spinner variant="forest" class="h-3.5 w-3.5" />
                                        @else
                                            <x-heroicon-o-document class="h-4 w-4 text-brand-moss" />
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium text-brand-ink">{{ $f['label'] }}</span>
                                        <span class="block truncate font-mono text-[10px] text-brand-mist">{{ $f['path'] }}</span>
                                    </span>
                                    @if (($f['size'] ?? 0) > 0)
                                        <span class="shrink-0 font-mono text-[10px] text-brand-mist">
                                            @if ($isLoading)
                                                {{ __('loading…') }}
                                            @else
                                                {{ number_format($f['size']) }}b
                                            @endif
                                        </span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</div>
