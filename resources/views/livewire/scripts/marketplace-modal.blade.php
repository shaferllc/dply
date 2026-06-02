{{-- Single unconditional root: this is a nested Livewire component, so its
     wire:id boundary must wrap a stable element (see the conditional-root
     trap). The <x-modal> itself teleports to <body>. --}}
<div>
    <x-modal name="{{ \App\Livewire\Scripts\MarketplaceModal::MODAL_NAME }}" max-width="2xl" focusable>
        <div class="flex flex-col" style="max-height: 80vh;">
            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-book-open class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Script presets') }}</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Add a script') }}</h2>
                            @if ($webserver !== '')
                                <span class="inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ ucfirst($webserver) }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Clone a starter into your organization’s scripts, then run it from Scripts or Server commands.') }}</p>
                    </div>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', '{{ \App\Livewire\Scripts\MarketplaceModal::MODAL_NAME }}')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            {{-- Search --}}
            <div class="border-b border-brand-ink/10 px-6 py-3">
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                    <input
                        type="search"
                        wire:model.live.debounce.250ms="search"
                        placeholder="{{ __('Filter presets…') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                    />
                </div>
            </div>

            {{-- List --}}
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                @if ($presets->isEmpty())
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-10 text-center text-sm text-brand-moss">
                        {{ __('No presets match.') }}
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($presets as $preset)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="preset-{{ $preset['key'] }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $preset['name'] }}</p>
                                    @if (! empty($preset['run_as_user']))
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Runs as') }} <code class="text-brand-ink">{{ $preset['run_as_user'] }}</code></p>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    wire:click="clonePreset('{{ $preset['key'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="clonePreset"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Add to my scripts') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-3">
                <a href="{{ route('scripts.marketplace', $webserver !== '' ? ['webserver' => $webserver] : []) }}" wire:navigate class="text-xs font-medium text-brand-forest hover:underline">
                    {{ __('Open full marketplace →') }}
                </a>
                <button type="button" x-on:click="$dispatch('close-modal', '{{ \App\Livewire\Scripts\MarketplaceModal::MODAL_NAME }}')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Done') }}
                </button>
            </div>
        </div>
    </x-modal>
</div>
