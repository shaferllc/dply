<div>
    @if ($this->canEdit)
        <div
            x-data="{ open: false }"
            x-on:env-quick-edit-open.window="open = true"
            x-on:env-quick-edit-saved.window="open = false"
            class="flex items-center"
            @if ($watchedConsoleRunId) wire:poll.3s="resolveWatchedConsoleAction" @endif
        >
            {{-- Quick .env editor toggle — sits next to Deploy / Console. --}}
            <button
                type="button"
                wire:click="loadEnv"
                x-on:click="open = true"
                title="{{ __('Edit this site’s environment variables') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-variable class="h-4 w-4" />
                {{ __('.env') }}
            </button>

            {{-- Slide-over: raw .env editor. Full rewrite + coalesced push. --}}
            <div x-show="open" x-cloak class="fixed inset-0 z-50" style="display: none;">
                <div class="absolute inset-0 bg-brand-ink/40" x-on:click="open = false" x-transition.opacity></div>
                <div
                    class="absolute right-0 top-0 flex h-full w-full max-w-lg flex-col bg-white shadow-2xl"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    x-on:keydown.escape.window="open = false"
                >
                    <div class="flex items-center justify-between border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Edit .env') }}</p>
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $site->name ?? $site->domain }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="save"
                                wire:loading.attr="disabled"
                                wire:target="save"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                            >
                                <x-heroicon-o-check class="h-4 w-4" wire:loading.remove wire:target="save" />
                                <span wire:loading wire:target="save" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                                {{ __('Save & push') }}
                            </button>
                            <button type="button" x-on:click="open = false" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <p class="mb-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('Edit the full .env below. Saving replaces every variable and pushes to the server — keys removed here are deleted from the live .env.') }}
                        </p>
                        <textarea
                            wire:model="env_text"
                            wire:loading.attr="disabled"
                            wire:target="loadEnv,save"
                            rows="22"
                            spellcheck="false"
                            autocapitalize="off"
                            autocomplete="off"
                            class="block w-full rounded-xl border-0 bg-brand-ink font-mono text-[12px] leading-relaxed text-brand-cream shadow-inner ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-inset focus:ring-brand-sage"
                            placeholder="KEY=value&#10;ANOTHER_KEY=value"
                        ></textarea>
                        @error('env_text')
                            <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
                        @enderror
                        <div class="mt-3 flex items-center justify-end">
                            <a
                                href="{{ route('sites.environment', ['server' => $server, 'site' => $site]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-forest hover:underline"
                            >
                                {{ __('Full environment editor') }}
                                <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
