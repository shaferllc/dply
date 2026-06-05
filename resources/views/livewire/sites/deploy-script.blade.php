@php $isEmbedded = $embedded ?? false; @endphp
<div>
@if (! $isEmbedded)
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
@else
    <div class="space-y-6">
@endif
        @php
            $phases = [
                'build' => ['label' => __('Build'), 'desc' => __('Runs after clone, before the release is activated — install dependencies, build assets.')],
                'release' => ['label' => __('Release'), 'desc' => __('Runs after the new release is activated — migrations, cache warming.')],
                'restart' => ['label' => __('Restart'), 'desc' => __('Runs after dply restarts services — restart your own workers/daemons.')],
            ];
            $snippets = $this->snippets();
        @endphp

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Deploy script') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-brand-moss">{{ __('Plain shell commands run on each deploy, by phase. Start from a preset, then tweak — or use “Insert” so you don’t have to remember the commands.') }}</p>
            </div>
            {{-- Presets --}}
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Preset') }}</span>
                @foreach ($this->presets() as $key => $preset)
                    <button type="button"
                        wire:click="applyPreset('{{ $key }}')"
                        wire:confirm="{{ __('Load the :preset preset? This replaces the current scripts (not saved until you click Save).', ['preset' => $preset['label']]) }}"
                        class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:border-brand-forest/40 hover:bg-brand-sand/40">
                        {{ $preset['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="mt-5 space-y-4">
            @foreach ($phases as $phase => $meta)
                <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ $meta['label'] }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $meta['desc'] }}</p>
                        </div>
                        @if (! empty($snippets[$phase]))
                            <div class="relative shrink-0" x-data="{ open: false }">
                                <button type="button" x-on:click="open = ! open"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Insert') }}
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5 text-brand-mist" />
                                </button>
                                <div x-show="open" x-cloak x-on:click.outside="open = false"
                                    class="absolute right-0 z-20 mt-1 w-64 overflow-hidden rounded-xl border border-brand-ink/10 bg-white py-1 shadow-lg">
                                    @foreach ($snippets[$phase] as $snippet)
                                        <button type="button"
                                            wire:click="insert('{{ $phase }}', @js($snippet['cmd']))"
                                            x-on:click="open = false"
                                            class="flex w-full flex-col items-start gap-0.5 px-3 py-1.5 text-left hover:bg-brand-sand/40">
                                            <span class="text-xs font-semibold text-brand-ink">{{ $snippet['label'] }}</span>
                                            <span class="font-mono text-[10px] text-brand-mist">{{ $snippet['cmd'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    <textarea wire:model="{{ $phase }}" rows="5" spellcheck="false"
                        placeholder="{{ __('# one command per line') }}"
                        class="mt-3 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs leading-5 text-brand-ink focus:border-brand-forest focus:ring-brand-forest"></textarea>
                </div>
            @endforeach
        </div>

        <div class="mt-5 flex items-center justify-end gap-3">
            <span wire:loading wire:target="save" class="text-xs text-brand-moss">{{ __('Saving…') }}</span>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                <x-heroicon-o-check class="h-4 w-4" /> {{ __('Save deploy script') }}
            </button>
        </div>
    </div>
</div>
