<section class="space-y-4">
    <div class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-brand-cream/40 px-6 py-4 sm:px-7">
            <nav aria-label="{{ __('Repository path') }}" class="min-w-0 flex-1">
                <ol class="flex flex-wrap items-center gap-1 text-sm">
                    @foreach ($filesBreadcrumb as $crumb)
                        @if ($loop->last)
                            <li class="font-mono font-semibold text-brand-ink">{{ $crumb['name'] }}</li>
                        @else
                            <li>
                                <button type="button" wire:click="navigateToPath('{{ $crumb['path'] }}')"
                                        class="font-mono text-brand-moss hover:text-brand-ink hover:underline">{{ $crumb['name'] }}</button>
                            </li>
                            <li class="text-brand-mist" aria-hidden="true">/</li>
                        @endif
                    @endforeach
                </ol>
            </nav>
            <label class="flex items-center gap-2 text-xs text-brand-moss">
                <span class="font-semibold uppercase tracking-[0.12em]">{{ __('Ref') }}</span>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="branchOverride"
                    placeholder="{{ $currentBranch }}"
                    class="w-32 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
        </div>
    </div>

    @if (! ($filesTree['ok'] ?? false))
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
            {{ $filesTree['error'] ?? __('Could not load this directory.') }}
        </div>
    @elseif (empty($filesTree['entries']))
        <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
            {{ __('Empty directory.') }}
        </div>
    @else
        <ul class="dply-card divide-y divide-brand-ink/10 overflow-hidden">
            @foreach ($filesTree['entries'] as $entry)
                <li class="flex items-center justify-between gap-3 px-4 py-2 hover:bg-brand-sand/20" wire:key="entry-{{ $entry['path'] }}">
                    @if ($entry['type'] === 'dir')
                        <button type="button" wire:click="navigateToPath('{{ $entry['path'] }}')"
                                class="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-moss" />
                            <span class="truncate font-mono text-sm text-brand-ink">{{ $entry['name'] }}</span>
                        </button>
                        <span class="text-[10px] uppercase tracking-[0.12em] text-brand-mist">{{ __('dir') }}</span>
                    @else
                        <button type="button" wire:click="openFile('{{ $entry['path'] }}')"
                                class="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <x-heroicon-o-document class="h-4 w-4 shrink-0 text-brand-moss" />
                            <span class="truncate font-mono text-sm text-brand-ink">{{ $entry['name'] }}</span>
                        </button>
                        <span class="font-mono text-xs text-brand-moss">{{ $entry['size'] > 0 ? number_format($entry['size'] / 1024, 1).' KB' : '—' }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($filesView !== null)
        <div class="dply-card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                <div class="min-w-0">
                    <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $filesOpenFile }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">
                        {{ $filesView['size'] > 0 ? number_format($filesView['size'] / 1024, 1).' KB' : __('—') }}
                        @if ($filesView['too_large'] ?? false)
                            · <span class="text-amber-900">{{ __('Too large to preview') }}</span>
                        @elseif ($filesView['binary'] ?? false)
                            · <span class="text-amber-900">{{ __('Binary file') }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @if (! empty($filesView['html_url']))
                        <a href="{{ $filesView['html_url'] }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                            {{ __('Open on provider') }}
                        </a>
                    @endif
                    <button type="button" wire:click="closeFile"
                            class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        {{ __('Close') }}
                    </button>
                </div>
            </header>

            @if (! ($filesView['ok'] ?? false))
                <div class="p-4 text-xs text-rose-900">{{ $filesView['error'] ?? __('Could not load file.') }}</div>
            @elseif (($filesView['too_large'] ?? false) || ($filesView['binary'] ?? false))
                <div class="p-6 text-center text-sm text-brand-moss">
                    {{ ($filesView['binary'] ?? false) ? __('Binary file — preview suppressed. Open on the provider to download.') : __('File exceeds the preview size limit. Open on the provider to download.') }}
                </div>
            @else
                <pre class="max-h-[40rem] overflow-auto bg-brand-ink p-4 font-mono text-[11px] leading-relaxed text-brand-cream/90">{{ $filesView['content'] }}</pre>
            @endif
        </div>
    @endif
</section>
