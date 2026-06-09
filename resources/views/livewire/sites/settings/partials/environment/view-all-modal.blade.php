    {{-- "View all" modal: pre-rendered .env block in a read-only textarea
         for select-all + copy. Defaults to masked (KEY=••••) so a casual
         open doesn't leak values into the screen / scrollback; one click
         flips to cleartext. The unmasked text is the same blob the pusher
         would write to the server, so the operator can confirm format. --}}
    @if ($variableCount > 0)
        @php
            // Build a masked version (KEY=••••) and the cleartext version
            // server-side so neither has to be re-derived in JS. Both go
            // into Alpine state below; the textarea binds to whichever
            // mode is currently selected.
            $maskedLines = [];
            $cleartextLines = [];
            $sortedEnvMap = $envMap;
            ksort($sortedEnvMap);
            foreach ($sortedEnvMap as $k => $v) {
                $cleartextLines[] = $k.'='.(string) $v;
                $len = strlen((string) $v);
                $maskedLines[] = $k.'='.($len === 0 ? '' : str_repeat('•', min(24, max(4, $len))));
            }
            $cleartextBlob = implode("\n", $cleartextLines);
            $maskedBlob = implode("\n", $maskedLines);
        @endphp
        <x-modal name="view-all-env-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
            <div
                x-data="{
                    revealed: false,
                    copied: false,
                    masked: @js($maskedBlob),
                    cleartext: @js($cleartextBlob),
                    get text() { return this.revealed ? this.cleartext : this.masked; },
                    async copy() {
                        try { await navigator.clipboard.writeText(this.cleartext); this.copied = true; setTimeout(() => this.copied = false, 1800); } catch (e) {}
                    },
                }"
            >
                <div class="relative border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Site variables') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('All variables') }}</h2>
                    <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                        {{ __('Read-only view of the full .env contents. Values are masked until you click Show — the Copy button always copies the cleartext.') }}
                    </p>
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        aria-label="{{ __('Close') }}"
                        title="{{ __('Close') }}"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="px-6 py-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                        <span class="text-[11px] uppercase tracking-[0.16em] text-brand-mist">
                            {{ trans_choice('{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                        </span>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" @click="revealed = !revealed" class="font-medium text-brand-sage hover:underline">
                                <span x-show="!revealed">{{ __('Show values') }}</span>
                                <span x-show="revealed" x-cloak>{{ __('Hide values') }}</span>
                            </button>
                            <button type="button" @click="copy()" class="font-medium text-brand-sage hover:underline">
                                <span x-show="!copied">{{ __('Copy all') }}</span>
                                <span x-show="copied" x-cloak class="text-emerald-700">{{ __('Copied') }}</span>
                            </button>
                        </div>
                    </div>
                    <textarea
                        readonly
                        rows="20"
                        class="w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        x-text="text"
                        @click="$event.target.select()"
                    ></textarea>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                    <p class="mr-auto text-xs text-brand-moss">{{ __('Use Paste .env to apply edits in bulk.') }}</p>
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>
    @endif
