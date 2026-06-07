<div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
    <div class="shrink-0 border-b border-brand-ink/10 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Files') }}</div>

    @if ($configCatalogLoading || ($opsReady && ! $configCatalogLoaded))
        <div class="flex min-h-0 flex-1 items-center justify-center gap-2 px-3 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" class="h-4 w-4 shrink-0" />
            <span>{{ __('Discovering config files on server…') }}</span>
        </div>
    @elseif ($configCatalogError)
        <p class="flex min-h-0 flex-1 items-center px-3 py-3 text-xs text-red-700">{{ $configCatalogError }}</p>
    @elseif (empty($groupedConfigFiles))
        <p class="flex min-h-0 flex-1 items-center px-3 py-3 text-xs text-brand-moss">{{ __('No config files discovered. Confirm the server is reachable.') }}</p>
    @else
        <div class="min-h-0 flex-1 overflow-y-auto">
            @foreach ($groupedConfigFiles as $groupKey => $group)
                <div class="border-b border-brand-ink/5 last:border-b-0">
                    <div class="sticky top-0 z-10 bg-brand-sand/80 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss backdrop-blur-sm">
                        {{ $group['label'] }}
                    </div>
                    <ul class="divide-y divide-brand-ink/5 text-sm">
                        @foreach ($group['files'] as $f)
                            @php
                                $isCached = ! empty($f['cached']);
                                $isSel = $config_selected_path === $f['path'];
                                $isLoading = ! $isCached && (
                                    $pending_load_path === $f['path']
                                    || ($config_selected_path === $f['path'] && ! $config_file_loaded)
                                );
                            @endphp
                            <li wire:key="config-file-{{ md5($f['path']) }}">
                                <button
                                    type="button"
                                    wire:click="loadConfigFile(@js($f['path']))"
                                    wire:target="loadConfigFile(@js($f['path']))"
                                    wire:loading.attr="disabled"
                                    x-on:click="$dispatch('config-file-cache-pick', { path: @js($isCached ? $f['path'] : null) })"
                                    @if (! $isCached)
                                        wire:loading.class="bg-brand-sand/60 cursor-progress"
                                    @endif
                                    data-skip-busy="1"
                                    @class([
                                        'flex w-full items-start gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/40',
                                        'bg-brand-sand/50' => $isSel && ! $isLoading,
                                        'bg-brand-sand/60 cursor-progress' => $isLoading,
                                    ])
                                >
                                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center">
                                        @if ($isCached)
                                            <x-heroicon-o-bolt class="h-4 w-4 text-sky-600" title="{{ __('Cached on this server') }}" />
                                        @elseif ($isLoading)
                                            <x-spinner variant="forest" class="h-4 w-4" />
                                        @else
                                            <span wire:loading.remove wire:target="loadConfigFile(@js($f['path']))" class="inline-flex">
                                                <x-heroicon-o-document class="h-4 w-4 text-brand-moss" />
                                            </span>
                                            <span wire:loading wire:target="loadConfigFile(@js($f['path']))" class="inline-flex">
                                                <x-spinner variant="forest" class="h-4 w-4" />
                                            </span>
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                                            <span class="truncate font-medium text-brand-ink">{{ $f['label'] }}</span>
                                            @if (! empty($f['role_label']))
                                                <x-config-file-role-pill :label="$f['role_label']" :role="$f['role'] ?? null" />
                                            @endif
                                            @if ($isCached)
                                                <span class="inline-flex shrink-0 items-center rounded bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                    {{ __('Cached') }}
                                                </span>
                                            @endif
                                        </span>
                                        <span class="block truncate font-mono text-[10px] text-brand-mist">{{ $f['path'] }}</span>
                                        @if (! empty($f['hint']))
                                            <span class="mt-1 line-clamp-2 block text-[10px] leading-snug text-brand-moss">{{ $f['hint'] }}</span>
                                        @endif
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
